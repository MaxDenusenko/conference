<?php

namespace app\modules\admin\controllers;

use app\models\Application;
use app\models\Conference;
use app\models\Letter;
use app\models\Material;
use app\models\Participant;
use app\models\User;
use yii\helpers\FileHelper;

class EmailController
{
    private $inboxData;
    private $searchKey;
    private $emailReadingLimit;
    public  $sessionMessage;

    /**
     * EmailController constructor.
     */
    public function __construct()
    {
        $this->inboxData         = unserialize(\Yii::$app->config->get('INBOX_DATA'));
        $this->searchKey         = \Yii::$app->config->get('HEADER_FOR_EMAIL_SEARCH');
        $this->emailReadingLimit = \Yii::$app->config->get('EMAIL_READING_LIMIT');

    }


    /**
     * @return bool|resource
     */
    private function getInbox()
    {
        try {
            $inbox = imap_open($this->inboxData['hostname'], $this->inboxData['username'], $this->inboxData['password']) or die('Cannot connect to Gmail: ' . imap_last_error());
        } catch (\Exception $exception) {
            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('error', "Не вдалося встановити з'єднання з сервером вхідної пошти (IMAP)");
            }
            return false;
        }

        return $inbox;
    }

    /**
     * @return array|bool
     */
    public function searchNewEmails()
    {
        if (!$inbox = $this->getInbox())
            return false;

        $newEmails = imap_search($inbox,"UNSEEN SUBJECT '$this->searchKey'", SE_FREE, 'UTF-8');

        imap_close($inbox);

        return $newEmails;
    }

    /**
     * @param $inbox
     * @param $emailNumber
     * @return array|bool
     */
    public function getInformationAboutSender($inbox, $emailNumber)
    {
        try {
            $message = base64_decode(imap_fetchbody($inbox, $emailNumber, 1.2));
            if (trim($message) == '')
                $message = base64_decode(imap_fetchbody($inbox, $emailNumber, 1));
            $header = imap_headerinfo($inbox, $emailNumber);
            $senderEmail = $header->from[0]->mailbox . "@" . $header->from[0]->host;
            $senderName = $header->from[0]->personal;
        } catch (\Exception $exception) {
            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('error', "Не вдалося отримати інформацію про відправника листа #$emailNumber");
            }
            return false;
        }

        return ['email' => $senderEmail, 'name' => $senderName, 'message' => $message];
    }

    /**
     * @param $inbox
     * @param $emailNumber
     * @return bool|object
     */
    public function getEmailStructure($inbox, $emailNumber)
    {
        try {
            $structure = imap_fetchstructure($inbox, $emailNumber);
        } catch (\Exception $exception) {
            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('error', "Не вдалося отримати структуру листа #$emailNumber");
            }
            return false;
        }

        return $structure;
    }

    /**
     * @param $dir string
     * @param $participantId integer
     * @param $dataSender integer
     * @param $application Application
     * @return bool
     */
    public function createNewMaterial($dir, $participantId, $dataSender, $application)
    {
        /** @var Material $material */
        /** @var Conference $conference */
        $conference = Conference::active();
        $material = Material::find()->where(['dir' => $dir])->andWhere(['conference_id' => $conference->id])->one();

        if ($material)
            return $material->id;

        /** @var Conference $conference */
        $material = new Material();
        $material->dir = $dir;
        $material->participant_id = $participantId;
        if ($application)
            $material->conference_id = $application->conference_id;
        else if ($conference = Conference::active())
            $material->conference_id = $conference->id;

        if ($material->save())
            return $material->id;
        if ($this->sessionMessage) {
            \Yii::$app->getSession()->setFlash('error', "Не вдалося створити матеріл листа від " . $dataSender['email']);
        }
        return false;
    }

    /**
     * @param $dataSender
     * @return bool|int|mixed
     */
    public function createNewParticipant($dataSender)
    {
        /** @var Participant $participant */
        $participant = Participant::find()->where(['email' => $dataSender['email']])->one();

        if ($participant)
            return $participant->id;

        $participant = new Participant();
        $participant->email = $dataSender['email'];
        $participant->name  = $dataSender['name'];

        if ($participant->save())
            return $participant->id;
        if ($this->sessionMessage) {
            \Yii::$app->getSession()->setFlash('error', "Не вдалося створити модель учасника листа від " . $dataSender['email']);
        }
        return false;
    }

    /**
     * @param $participantId integer
     * @param $dataSender array
     * @param $dir string
     * @param $emailNumber integer
     * @param $materialId integer
     * @return bool
     */
    public function createNewLetter($participantId, $dataSender,$dir, $emailNumber, $materialId = null)
    {
        /** @var Conference $conference */
        /** @var User $user */
        /** @var Letter $letter */
        /** @var Application $application */
        $conference = Conference::active();
        if (trim($dir, '/') == $emailNumber) {

            $letter = Letter::find()->where(['material_id' => $materialId])->andWhere(['conference_id' => $conference->id])->andWhere(['material_id' => $materialId])->count();
            if ($letter)
                return true;
        }
        $user = User::find()->where(['email' => $dataSender['email']])->one();
        $application = Application::find()->where(['email' => $dataSender['email']])->andWhere(['conference_id' => $conference->id])->one();

        $letter = new Letter();
        $letter->participant_id = $participantId;
        $letter->email          = $dataSender['email'];
        $letter->material_id    = $materialId;
        $letter->conference_id  = $conference->id;
        $letter->message        = $dataSender['message'];
        if ($user)
            $letter->user_id    = $user->id;

        if ($application)
            $letter->application_id = $application->id;

        if (!$letter->save()) {
            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('error', "Не вдалося створити модель листа від " . $dataSender['email']);
            }
            return false;
        }
        return true;
    }

    /**
     * @param $newEmails
     * @param bool $sessionMessage
     * @return bool
     */
    public function readingEmail($newEmails, $sessionMessage = true)
    {
        $this->sessionMessage = $sessionMessage;

        if (!file_exists(\Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'])) {

            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('error', "Директорії /attachments не існує");
            }
            return false;
        }

        if($newEmails)
        {
            if (!$inbox = $this->getInbox())
                return false;

            $count = 1;

            if (is_array($newEmails) && (count($newEmails) > 0))
                rsort($newEmails);

            foreach($newEmails as $emailNumber) {

                if (!$emailStructure = $this->getEmailStructure($inbox, $emailNumber))
                    return false;

                if (!$dataSender = $this->getInformationAboutSender($inbox, $emailNumber))
                    return false;

                if (!$participantId = $this->createNewParticipant($dataSender))
                    return false;
                $materialId = null;

                if ($dir = $this->createFile($emailStructure, $inbox, $emailNumber, $dataSender)) {

                    $application = $this->findApplication($dataSender);

                    if (!$materialId = $this->createNewMaterial($dir, $participantId, $dataSender, $application))
                        return false;

                    /** @var Application $application */
                    if (is_object($application) && !$application->material_id)
                        $this->updateApplication($materialId, $application);

                }

                if (!$letter = $this->createNewLetter($participantId, $dataSender, $dir, $emailNumber, $materialId))
                    return false;

                if (!$this->setFlag($inbox, $emailNumber, "\\Seen \\Flagged"))
                    return false;

                if($count++ >= $this->emailReadingLimit) break;

            }

            imap_close($inbox);

            return true;
        }
        if ($this->sessionMessage) {
            \Yii::$app->getSession()->setFlash('error', 'Не знайдено нових листів');
        }
        return false;
    }

    /**
     * @param $dataSender
     * @return array|bool|null|\yii\db\ActiveRecord
     */
    public function findApplication($dataSender)
    {
        /** @var Conference $conference */
        $conference = Conference::find()->where(['status' => 1])->one();
        $application = Application::find()->where(['email' => $dataSender['email']])->andWhere(['conference_id' => $conference->id])->one();

        if ($application)
            return $application;
        return false;
    }

    /**
     * @param $materialId
     * @param $application
     */
    public function updateApplication($materialId, $application)
    {
        if ($application) {
            /** @var Application $application */
            $application->material_id = $materialId;
            $application->save(false);
        }
    }

    /**
     * @param $inbox
     * @param $emailNumber
     * @param $flag
     * @return bool
     */
    public function setFlag($inbox, $emailNumber, $flag)
    {
        if (imap_setflag_full($inbox, $emailNumber, $flag))
            return true;

        if ($this->sessionMessage) {
            \Yii::$app->getSession()->setFlash('error', "Не вдалося відмітити лист $emailNumber як прочитаний");
        }
        return false;
    }


    /**
     * Create files for a attachments
     * @param $structure
     * @param $inbox
     * @param $emailNumber
     * @param $dataSender
     * @return bool|string
     */
    public function createFile($structure, $inbox, $emailNumber, $dataSender)
    {
        if(isset($structure->parts) && count($structure->parts)) {
            for ($i = 0; $i < count($structure->parts); $i++) {
                $attachments[$i] = array(
                    'is_attachment' => false,
                    'filename' => '',
                    'name' => '',
                    'attachment' => ''
                );

                if($structure->parts[$i]->ifdparameters)
                {
                    foreach($structure->parts[$i]->dparameters as $object)
                    {
                        if(strtolower($object->attribute) == 'filename')
                        {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['filename'] = $object->value;
                        }
                    }
                }

                if($structure->parts[$i]->ifparameters)
                {
                    foreach($structure->parts[$i]->parameters as $object)
                    {

                        if(strtolower($object->attribute) == 'name')
                        {
                            $attachments[$i]['is_attachment'] = true;
                            $attachments[$i]['name'] = $object->value;
                        }
                    }
                }

                if($attachments[$i]['is_attachment'])
                {
                    $attachments[$i]['attachment'] = imap_fetchbody($inbox, $emailNumber, $i+1);

                    /* 3 = BASE64 encoding */
                    if($structure->parts[$i]->encoding == 3)
                    {
                        $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
                    }
                    /* 4 = QUOTED-PRINTABLE encoding */
                    elseif($structure->parts[$i]->encoding == 4)
                    {
                        $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
                    }
                }

            }
        }
        if (empty($attachments[1]['is_attachment'])) {

            if ($this->sessionMessage) {
                \Yii::$app->getSession()->setFlash('info', "Не зайнайдено прикріплених файлів в листі від " . $dataSender['email']);
            }
            return false;
        }

        /** @var Conference $conference */
        /** @var Letter $letter */
        /** @var Application $application */
        $conference = Conference::active();
        $letter = Letter::find()->where(['email' => $dataSender['email']])->andWhere(['not', ['material_id' => null]])->andWhere(['conference_id' => $conference->id])->one();
        $application = Application::find()->where(['email' => $dataSender['email']])->andWhere(['not', ['material_id' => null]])->andWhere(['conference_id' => $conference->id])->one();
        if ($letter && $letter->material_id) {

            if (is_dir(\Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$letter->material->dir.'/'))
                $dirPath = \Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$letter->material->dir.'/';
            else
                $dirPath = \Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$emailNumber.'/';
        }
        else if ($application && $application->material_id){

            if (is_dir(\Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$application->material->dir.'/'))
                $dirPath = \Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$application->material->dir.'/';
            else
                $dirPath = \Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$emailNumber.'/';
        }
        else
            $dirPath = \Yii::$app->getBasePath().\Yii::$app->params['PathToAttachments'].$emailNumber.'/';

        if (!is_dir($dirPath)) {
            try {
                mkdir($dirPath);
            } catch (\Exception $exception) {
                if ($this->sessionMessage) {
                    \Yii::$app->getSession()->setFlash('error', "Не вдалося створити директорію $dirPath для листа від " . $dataSender['email']);
                }
                return false;
            }
        }

        foreach($attachments as $attachment)
        {
            if($attachment['is_attachment'] == 1)
            {
                $filename = imap_utf8($attachment['name']);
                if(empty($filename)) $filename = imap_utf8($attachment['filename']);

                if(empty($filename)) $filename = time() . ".dat";

                /* prefix the email number to the filename in case two emails
                 * have the attachment with the same file name.
                 */
                $filename = $emailNumber . "_" . $filename;

                $dateDir = $dirPath . date('Y-m-d H:i:s') . '/';
                if (!file_exists($dateDir)) {
                    try {
                        FileHelper::createDirectory($dateDir);
                    } catch (\Exception $exception) {
                        if ($this->sessionMessage) {
                            \Yii::$app->getSession()->setFlash('error', "Не вдалося створити файли для листа від " . $dataSender['email']);
                        }
                        return false;
                    }
                }

                $filePath = $dateDir . $filename;

                try {
                    $fp = fopen($filePath, "w+");

                    fwrite($fp, $attachment['attachment']);
                    fclose($fp);
                } catch (\Exception $exception) {
                    if ($this->sessionMessage) {
                        \Yii::$app->getSession()->setFlash('error', "Не вдалося створити файли для листа від " . $dataSender['email']);
                    }
                    return false;
                }

            }

        }
        $result = mb_strrchr(trim($dirPath, '/'), '/').'/';

        return $result;
    }
}
