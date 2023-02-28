<?php

declare(strict_types=1);

namespace App\Service\External\Synology;

use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FileSystemWs
{

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ParameterBagInterface $params,
    ){ }

    /**
     * pomocna metoda, ktora upravi vstupny retazec na retazec vyhovujuci pre Synology
     */
    private function sanitizePath(string $path, bool $useBrackets): string
    {
        $path = str_replace('/', '%2F', $path);
        if ($useBrackets) {
            $path = '%5B%22'.$path.'%22%5D';
        }
        return $path;
    }

    /**
     * API Informacie o synology
     */
    public function getApiInfo(): ?array
    {
        $url = $this->params->get('synology_base_url').'/webapi/query.cgi?Api=SYNO.API.Info&version=1&method=query';
        try {
            $res = $this->httpClient->request('GET', $url, [
                'verify_peer' => false,
                'verify_host' => false
            ]);
            if ($res->getStatusCode() === 200) {
                return json_decode($res->getContent(true), true);
            }
            return null;
        } catch(ExceptionInterface) {
            return null;
        }
    }

    /**
     * prihlasenie do synology a ziskanie SID
     * @throws Exception
     */
    public function loginAndGetSid(): ?string
    {
        $url = $this->params->get('synology_base_url').'/webapi/auth.cgi?Api=SYNO.API.Auth&version=6&method=login&account='
            .$this->params->get('synology_username').'&passwd='.$this->params->get('synology_password');
        try {
            $res = $this->httpClient->request('GET', $url, [
                    'verify_peer' => false,
                    'verify_host' => false
                ]
            );

            if ($res->getStatusCode() === 200) {
                $responseJson = json_decode($res->getContent(), true);
                return $responseJson['data']['sid'] ?? null;
            }
            return null;
        } catch(ExceptionInterface $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * odhlasenie
     * @throws Exception
     */
    public function logout(): void
    {
        $url = $this->params->get('synology_base_url').'/webapi/auth.cgi?Api=SYNO.API.Auth&version=6&method=logout&account='
            .$this->params->get('synology_username').'&passwd='.$this->params->get('synology_password');
        try {
            $res = $this->httpClient->request('GET', $url, [
                'verify_peer' => false,
                'verify_host' => false
            ]);
            if ($res->getStatusCode() !== 200) {
                throw new Exception('Request logout neúspešný');
            }
        }catch(ExceptionInterface $e){
            throw new Exception('Request logout neúspešný:'.$e->getMessage());
        }
    }

    /**
     * upload jedneho suboru
     * @throws Exception
     */
    public function uploadFile(string $destinationDirPath, string $sourceFilePath, string $sid): bool
    {
        $url = $this->params->get('synology_base_url').'/webapi/entry.cgi?_sid='.$sid;

        // nasledujuci postup neisiel cez symfony http client, preto je to cez curl.
        $cfile = new \CURLFile($sourceFilePath, 'application/octet-stream');
        $cfile->setPostFilename(basename($sourceFilePath));
        $fields = [
            'Api' => 'SYNO.FileStation.Upload',
            'version' => '2',
            'method' => 'upload',
            'path' => $destinationDirPath,
            'create_parents' => 'true',
            'file' => $cfile
        ];

        try {
            $ch = curl_init();
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_POST => count($fields),
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_RETURNTRANSFER => true,   // return web page
                CURLOPT_AUTOREFERER => true,   // set referrer on redirect
                CURLOPT_CONNECTTIMEOUT => 90,    // time-out on connect
                CURLOPT_TIMEOUT => 90,    // time-out on response
//                CURLOPT_SSL_VERIFYHOST => false,    // disable verify ssl host
//                CURLOPT_SSL_VERIFYPEER => false    // disable verify ssl cert
            ];
            curl_setopt_array($ch, $options);

            //execute post
            $responseText = curl_exec($ch);
            if ($responseText !== false) {
                curl_close($ch);
                return true;
            }
        } catch(Exception $e) {
            throw new Exception($e->getMessage().'->'.$e->getTraceAsString());
        }
        return false;
    }

    /**
     * vytvorit adresar
     * @throws Exception
     */
    public function createFolder(string $folderPath, string $folderName, string $sid): void
    {
        $url = $this->params->get('synology_base_url').'/webapi/entry.cgi?Api=SYNO.FileStation.CreateFolder&version=2&method=create&folder_path='.
            $this->sanitizePath($folderPath, true).'&name='.$this->sanitizePath($folderName, false).'&_sid='.$sid;
        try {
            $res = $this->httpClient->request('GET', $url, [
                'verify_peer' => false,
                'verify_host' => false
            ]);
            if ($res->getStatusCode() !== 200) {
                throw new Exception('Request createFolder neúspešný');
            }
        } catch(ExceptionInterface $e) {
            throw new Exception('Request createFolder neúspešný:'.$e->getMessage());
        }
    }

    /**
     * vymazanie adresara/suboru blokovacim sposobom, ked je response vrateny az ked vymazanie dobehne
     * @throws Exception
     */
    public function delete(string $path, string $sid): void
    {
        $url = $this->params->get('synology_base_url').'/webapi/entry.cgi?Api=SYNO.FileStation.Delete&version=1&method=delete&path="'.
            $this->sanitizePath($path, false).'"&_sid='.$sid;
        try {
            $res = $this->httpClient->request('GET', $url, [
                'verify_peer' => false,
                'verify_host' => false
            ]);
            if ($res->getStatusCode() !== 200) {
                throw new Exception('Request delete neúspešný');
            }
        } catch(ExceptionInterface $e) {
            throw new Exception('Request delete neúspešný:'.$e->getMessage());
        }
    }

    /**
     * download jedneho suboru
     * @throws Exception
     */
    public function downloadFile(string $filePath, string $sid): ?string
    {
        $url = $this->params->get('synology_base_url').'/webapi/entry.cgi?Api=SYNO.FileStation.Download&version=2&method=download&path='
            .$this->sanitizePath($filePath, true).'&mode=%22open%22&_sid='.$sid;
        try {
            $res = $this->httpClient->request('GET', $url, [
                'verify_peer' => false,
                'verify_host' => false
            ]);

            if ($res->getStatusCode() !== 200) {
                throw new Exception('Request download neúspešný');
            }
            return $res->getContent();
        } catch(ExceptionInterface $e) {
            throw new Exception('Request download neúspešný:'.$e->getMessage());
        }
    }

}
