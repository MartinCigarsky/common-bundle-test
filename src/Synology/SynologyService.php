<?php

declare(strict_types=1);

namespace App\Service\External\Synology;

use App\Entity\Calendar\Attendance;
use App\Entity\Subject\Subject;
use App\Service\AbstractBaseManager;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Sentia\Utils\SentiaUtils;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SynologyService extends AbstractBaseManager
{
    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly FileSystemWs $fileSystemWs,
        protected EntityManagerInterface $em,
        protected SentiaUtils $utils,
    ){
        parent::__construct($em, $utils);
    }

    /**
     * @throws Exception
     */
    public function moveAttendanceFileToArchive(Attendance $attendance, Subject $subjectUser): void
    {
        if ($attendance->getFileExtension() === null) {
            return;
        }

        $sourcePathFile = $this->params->get('path_data_temp').'/'.$attendance->getUuid()->toRfc4122().'.'.$attendance->getFileExtension();
        $targetDir = $this->utils->file->getPathByUuid("/rosetta_".($this->params->get('is_prod') ? "prod" : "test")
            ."/office_users/", $subjectUser->getUuid()->toRfc4122());
        $targetDir .= '/attendance_files';

        $sid = $this->fileSystemWs->loginAndGetSid();
        $this->fileSystemWs->uploadFile($targetDir, $sourcePathFile, $sid);
        $this->fileSystemWs->logout();
    }

    /**
     * @throws Exception
     */
    public function downloadAttendanceFile(Attendance $attendance, Subject $subjectUser): ?string
    {
        if($attendance->getFileExtension() === null) {
            return null;
        }
        $path = $this->utils->file->getPathByUuid("/rosetta_".($this->params->get('is_prod') ? "prod" : "test")
            ."/office_users/", $subjectUser->getUuid()->toRfc4122());
        $path .= '/attendance_files/'.$attendance->getUuid()->toRfc4122().'.'.$attendance->getFileExtension();
        $sid = $this->fileSystemWs->loginAndGetSid();
        return $this->fileSystemWs->downloadFile($path, $sid);
    }

    /**
     * @throws Exception
     */
    public function deleteAttendanceFile(Attendance $attendance, Subject $subjectUser): void
    {
        if($attendance->getFileExtension() === null) {
            return;
        }
        $dirPath = $this->utils->file->getPathByUuid("/rosetta_".($this->params->get('is_prod') ? "prod" : "test")
            ."/office_users/", $subjectUser->getUuid()->toRfc4122());
        $dirPath .= '/attendance_files/'.$attendance->getUuid()->toRfc4122().'.'.$attendance->getFileExtension();
        $sid = $this->fileSystemWs->loginAndGetSid();
        $this->fileSystemWs->delete($dirPath, $sid);
    }

    /**
     * @throws Exception
     */
    public function deleteUserFolder(Subject $subjectUser): void
    {
        $dirPath = $this->utils->file->getPathByUuid("/rosetta_".($this->params->get('is_prod') ? "prod" : "test")
            ."/office_users/", $subjectUser->getUuid()->toRfc4122());
        $sid = $this->fileSystemWs->loginAndGetSid();
        $this->fileSystemWs->delete($dirPath, $sid);
    }
}
