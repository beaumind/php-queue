<?php
namespace PHPQueue;

use PHPQueue\Exception\QueueNotFoundException;
use PHPQueue\Exception\WorkerNotFoundException;

class Base
{
    public static $queue_path = null;
    public static $worker_path = null;
    public static $queue_namespace = null;
    public static $worker_namespace = null;
    private static $all_queues = array();
    private static $all_workers = array();
    private static $config_class;

    /**
     * @param  string             $queue
     * @param  array              $options
     * @return \PHPQueue\JobQueue
     */
    public static function getQueue($queue)
    {
        if (empty($queue)) {
            throw new \InvalidArgumentException("Queue name is empty");
        }
        if ( empty(self::$all_queues[$queue]) ) {
            $className = self::loadAndGetQueueClassName($queue);
            try {
                self::$all_queues[$queue] = new $className();
            } catch (\Exception $ex) {
                throw new QueueNotFoundException($ex->getMessage(), $ex->getCode());
            }
        }

        return self::$all_queues[$queue];
    }

    protected static function loadAndGetQueueClassName($queue_name)
    {
        $class_name = self::loadAndReturnFullClassName($queue_name, 'Queue');
        if (empty($class_name))
            throw new QueueNotFoundException("Queue file/class does not exist: $queue_name");
        return $class_name;
    }

    /**
     * @param  \PHPQueue\JobQueue $queue
     * @param  array              $newJob
     * @return boolean
     */
    public static function addJob(JobQueue $queue, $newJob)
    {
        if (empty($newJob)) {
            throw new \InvalidArgumentException("Invalid job data.");
        }
        $status = false;
        try {
            $queue->beforeAdd($newJob);
            $status = $queue->addJob($newJob);
            $queue->afterAdd();
        } catch (\Exception $ex) {
            $queue->onError($ex);
            throw $ex;
        }

        return $status;
    }

    /**
     * @param  \PHPQueue\JobQueue $queue
     * @param  string             $jobId
     * @return \PHPQueue\Job
     */
    public static function getJob(JobQueue $queue, $jobId=null)
    {
        $job = null;
        try {
            $queue->beforeGet();
            $job = $queue->getJob($jobId);
            $queue->afterGet();
        } catch (\Exception $ex) {
            $queue->onError($ex);
            throw $ex;
        }

        return $job;
    }

    /**
     * @param  \PHPQueue\JobQueue $queue
     * @param  string             $jobId
     * @param  mixed              $resultData
     * @return boolean
     */
    public static function updateJob(JobQueue $queue, $jobId=null, $resultData=null)
    {
        $status = false;
        try {
            $queue->beforeUpdate();
            $queue->updateJob($jobId, $resultData);
            $status = $queue->clearJob($jobId);
            $queue->afterUpdate();
        } catch (\Exception $ex) {
            $queue->onError($ex);
            $queue->releaseJob($jobId);
            throw $ex;
        }

        return $status;
    }

    /**
     * @param  string              $worker_name
     * @param  array               $options
     * @return \PHPQueue\Worker
     * @throws \PHPQueue\Exception
     */
    public static function getWorker($worker_name)
    {
        if (empty($worker_name)) {
            throw new \InvalidArgumentException("Worker name is empty");
        }
        if ( empty(self::$all_workers[$worker_name]) ) {
            $className = self::loadAndGetWorkerClassName($worker_name);
            try {
                self::$all_workers[$worker_name] = new $className();
            } catch (\Exception $ex) {
                throw new WorkerNotFoundException($ex->getMessage(), $ex->getCode());
            }
        }

        return self::$all_workers[$worker_name];
    }

    protected static function loadAndGetWorkerClassName($worker_name)
    {
        $class_name = self::loadAndReturnFullClassName($worker_name, 'Worker');
        if (empty($class_name))
            throw new WorkerNotFoundException("Worker file/class does not exist: $worker_name");
        return $class_name;
    }

    /**
     * @param  \PHPQueue\Worker    $worker
     * @param  \PHPQueue\Job       $job
     * @return \PHPQueue\Worker
     * @throws \Exception
     */
    public static function workJob(Worker $worker, Job $job)
    {
        try {
            $worker->beforeJob($job->data);
            $worker->runJob($job);
            $job->onSuccessful();
            $worker->afterJob();
            $worker->onSuccess();
        } catch (\Exception $ex) {
            $worker->onError($ex);
            $job->onError();
            throw $ex;
        }

        return $worker;
    }

    /**
     * Factory method to instantiate a copy of a backend class.
     * @param  string                      $type    Case-sensitive class name.
     * @param  array                       $options Constuctor options.
     * @return \PHPQueue\backend_classname
     * @throws \InvalidArgumentException
     */
    public static function backendFactory($type, $options=array())
    {
        $backend_classname = '\\PHPQueue\\Backend\\' . $type;
        $obj = new $backend_classname($options);
        if ($obj instanceof $backend_classname) {
            return $obj;
        } else {
            throw new \InvalidArgumentException("Invalid Backend object.");
        }
    }

    private static function loadAndReturnFullClassName($org_class_name, $class_suffix='')
    {
        $full_class_name = '';
        if (!is_null(self::$queue_path)) {
            if ( !is_array(self::$queue_path) ) {
                self::$queue_path = array(self::$queue_path);
            }
            foreach(self::$queue_path as $path) {
                $classFile = $path . '/' . $org_class_name . $class_suffix . '.php';
                if (is_file($classFile)) {
                    require_once $classFile;
                    return "\\" . $org_class_name . $class_suffix;
                }
            }
        }
        if (!is_null(self::$queue_namespace)) {
            if (!is_array(self::$queue_namespace)) {
                self::$queue_namespace = array(self::$queue_namespace);
            }
            foreach(self::$queue_namespace as $namespace)
            {
                if (!(strpos($namespace, "\\") === 0)) {
                    $full_class_name = "\\";
                }
                $full_class_name .= $namespace . "\\" . $org_class_name;
                if (class_exists($full_class_name, true)) {
                    return $full_class_name;
                }
            }
        }
        return $full_class_name;
    }
}
