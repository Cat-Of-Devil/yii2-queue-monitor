<?php
/**
 * @link https://github.com/zhuravljov/yii2-queue-monitor
 * @copyright Copyright (c) 2017 Roman Zhuravlev
 * @license http://opensource.org/licenses/BSD-3-Clause
 */

namespace zhuravljov\yii\queue\monitor;

use Yii;
use yii\base\InvalidConfigException;
use yii\queue\ErrorEvent;
use yii\queue\ExecEvent;
use yii\queue\JobEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;
use zhuravljov\yii\queue\monitor\records\ExecRecord;
use zhuravljov\yii\queue\monitor\records\PushRecord;

/**
 * Queue Monitor Behavior
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class Behavior extends \yii\base\Behavior
{
    /**
     * @var Env
     */
    protected $env;

    /**
     * @param Env $env
     * @param array $config
     */
    public function __construct(Env $env, $config = [])
    {
        $this->env = $env;
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            Queue::EVENT_AFTER_PUSH => 'afterPush',
            Queue::EVENT_BEFORE_EXEC => 'beforeExec',
            Queue::EVENT_AFTER_EXEC => 'afterExec',
            Queue::EVENT_AFTER_ERROR => 'afterError',
        ];
    }

    public function afterPush(PushEvent $event)
    {
        $this->checkEvent($event);

        $push = new PushRecord();
        $push->sender = $this->getSenderName($event);
        $push->job_uid = $event->id;
        $push->job_class = get_class($event->job);
        $push->job_object = serialize($event->job);
        $push->ttr = $event->ttr;
        $push->delay = $event->delay;
        $push->pushed_at = time();
        $push->save(false);
    }

    public function beforeExec(ExecEvent $event)
    {
        $this->checkEvent($event);

        $this->env->db->transaction(function () use ($event) {
            if ($push = $this->getPushRecord($event)) {
                $exec = new ExecRecord();
                $exec->push_id = $push->id;
                $exec->attempt = $event->attempt;
                $exec->reserved_at = time();
                $exec->save(false);

                $push->first_exec_id = $push->first_exec_id ?: $exec->id;
                $push->last_exec_id = $exec->id;
                $push->save(false);
            }
        });
    }

    public function afterExec(ExecEvent $event)
    {
        $this->checkEvent($event);

        if ($push = $this->getPushRecord($event)) {
            ExecRecord::updateAll([
                'done_at' => time(),
                'error' => null,
                'retry' => false,
            ], [
                'id' => $push->last_exec_id
            ]);
        }
    }

    public function afterError(ErrorEvent $event)
    {
        $this->checkEvent($event);

        if ($push = $this->getPushRecord($event)) {
            ExecRecord::updateAll([
                'done_at' => time(),
                'error' => $event->error,
                'retry' => $event->retry,
            ], [
                'id' => $push->last_exec_id
            ]);
        }
    }

    /**
     * @param JobEvent $event
     * @return string
     * @throws
     */
    protected function getSenderName(JobEvent $event)
    {
        foreach (Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $event->sender) {
                return $id;
            }
        }
        throw new InvalidConfigException('Queue must be an application component.');
    }

    /**
     * @param JobEvent $event
     * @return PushRecord
     */
    protected function getPushRecord(JobEvent $event)
    {
        if ($event->id !== null) {
            return PushRecord::find()
                ->byJob($this->getSenderName($event), $event->id)
                ->one();
        } else {
            return null;
        }
    }

    /**
     * @param JobEvent $event
     * @throws InvalidConfigException
     */
    private function checkEvent(JobEvent $event)
    {
        if ($event->id === null) {
            throw new InvalidConfigException(strtr('Queue monitor does not support {class}.', [
                '{class}' => get_class($event->sender),
            ]));
        }
    }
}