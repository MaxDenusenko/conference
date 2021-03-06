<?php

namespace app\models\job;


use yii\base\BaseObject;
use yii\queue\JobInterface;

class MassSendMailJob extends BaseObject implements JobInterface
{
    public $participant;
    public $mailingData;

    /**
     * Mailing for a participants conference
     * @param \yii\queue\Queue $queue
     * @return bool
     */
    public function execute($queue)
    {
        foreach ($this->participant as $model) {

            if($model->email) {

                try {
                    $sendGrid = \Yii::$app->sendGrid;
                    $message = $sendGrid->compose('mailing', ['mailingData' => $this->mailingData]);
                    $message->setFrom([\Yii::$app->config->get('SUPPORT_EMAIL') => \Yii::$app->name])
                        ->setTo($model->email)
                        ->setSubject($this->mailingData->subject)
                        ->send($sendGrid);
                } catch (\Exception $exception) {
                    return false;
                }

            }

        }

        return true;
    }
}
