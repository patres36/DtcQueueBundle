<?php

namespace Dtc\QueueBundle\DependencyInjection\Compiler;

use Dtc\QueueBundle\Model\Job;
use Dtc\QueueBundle\Model\Run;
use Pheanstalk\Pheanstalk;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class WorkerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (false === $container->hasDefinition('dtc_queue.worker_manager')) {
            return;
        }

        $defaultManagerType = $container->getParameter('dtc_queue.default_manager');
        if (!$container->hasDefinition('dtc_queue.job_manager.'.$defaultManagerType)) {
            throw new \Exception("No job manager found for dtc_queue.job_manager.$defaultManagerType");
        }

        $alias = new Alias('dtc_queue.job_manager.'.$defaultManagerType);
        $container->setAlias('dtc_queue.job_manager', $alias);

        // Setup beanstalkd if configuration is present
        $this->setupBeanstalkd($container);
        $this->setupRabbitMQ($container);

        $definition = $container->getDefinition('dtc_queue.worker_manager');
        $jobManagerRef = array(new Reference('dtc_queue.job_manager'));

        $jobClass = $this->getJobClass($container);
        $jobClassArchive = $this->getJobClassArchive($container);
        $container->setParameter('dtc_queue.class_job', $jobClass);
        $container->setParameter('dtc_queue.class_job_archive', $jobClassArchive);
        $container->setParameter('dtc_queue.class_run', $this->getRunClass($container));
        $container->setParameter('dtc_queue.class_run_archive', $this->getRunArchiveClass($container));
        // Add each worker to workerManager, make sure each worker has instance to work
        foreach ($container->findTaggedServiceIds('dtc_queue.worker') as $id => $attributes) {
            $worker = $container->getDefinition($id);
            $class = $container->getDefinition($id)->getClass();

            $refClass = new \ReflectionClass($class);
            $workerClass = 'Dtc\QueueBundle\Model\Worker';
            if (!$refClass->isSubclassOf($workerClass)) {
                throw new \InvalidArgumentException(sprintf('Service "%s" must extend class "%s".', $id, $workerClass));
            }

            // Give each worker access to job manager
            $worker->addMethodCall('setJobManager', $jobManagerRef);
            $worker->addMethodCall('setJobClass', array($jobClass));

            $definition->addMethodCall('addWorker', array(new Reference($id)));
        }

        $eventDispatcher = $container->getDefinition('dtc_queue.event_dispatcher');
        foreach ($container->findTaggedServiceIds('dtc_queue.event_subscriber') as $id => $attributes) {
            $eventSubscriber = $container->getDefinition($id);
            $eventDispatcher->addMethodCall('addSubscriber', [$eventSubscriber]);
        }
        $this->setupDoctrineManagers($container);
    }

    /**
     * Sets up beanstalkd instance if appropriate.
     *
     * @param ContainerBuilder $container
     */
    public function setupBeanstalkd(ContainerBuilder $container)
    {
        if ($container->hasParameter('dtc_queue.beanstalkd.host')) {
            $definition = new Definition('Pheanstalk\\Pheanstalk', [$container->getParameter('dtc_queue.beanstalkd.host')]);
            $container->setDefinition('dtc_queue.beanstalkd', $definition);
            $definition = $container->getDefinition('dtc_queue.job_manager.beanstalkd');
            $definition->addMethodCall('setBeanstalkd', [new Reference('dtc_queue.beanstalkd')]);
            if ($container->hasParameter('dtc_queue.beanstalkd.tube')) {
                $definition->addMethodCall('setTube', [$container->getParameter('dtc_queue.beanstalkd.tube')]);
            }
        }
    }

    public function setupDoctrineManagers(ContainerBuilder $container)
    {
        $documentManager = $container->getParameter('dtc_queue.document_manager');

        $odmManager = "doctrine_mongodb.odm.{$documentManager}_document_manager";
        if ($container->has($odmManager)) {
            $container->setAlias('dtc_queue.document_manager', $odmManager);
        }

        $entityManager = $container->getParameter('dtc_queue.entity_manager');

        $ormManager = "doctrine.orm.{$entityManager}_entity_manager";
        if ($container->has($ormManager)) {
            $container->setAlias('dtc_queue.entity_manager', $ormManager);
        }
    }

    /**
     * Sets up RabbitMQ instance if appropriate.
     *
     * @param ContainerBuilder $container
     */
    public function setupRabbitMQ(ContainerBuilder $container)
    {
        if ($container->hasParameter('dtc_queue.rabbit_mq')) {
            $class = 'PhpAmqpLib\\Connection\\AMQPStreamConnection';
            $rabbitMqConfig = $container->getParameter('dtc_queue.rabbit_mq');
            $arguments = [
                $rabbitMqConfig['host'],
                $rabbitMqConfig['port'],
                $rabbitMqConfig['user'],
                $rabbitMqConfig['password'],
                $rabbitMqConfig['vhost'],
            ];

            if ($container->hasParameter('dtc_queue.rabbit_mq.ssl') && $container->getParameter('dtc_queue.rabbit_mq.ssl')) {
                $class = 'PhpAmqpLib\\Connection\\AMQPSSLConnection';
                if ($container->hasParameter('dtc_queue.rabbit_mq.ssl_options')) {
                    $arguments[] = $container->getParameter('dtc_queue.rabbit_mq.ssl_options');
                } else {
                    $arguments[] = [];
                }
                if ($container->hasParameter('dtc_queue.rabbit_mq.options')) {
                    $arguments[] = $container->getParameter('dtc_queue.rabbit_mq.options');
                }
            } else {
                if ($container->hasParameter('dtc_queue.rabbit_mq.options')) {
                    $options = $container->getParameter('dtc_queue.rabbit_mq.options');
                    if (isset($options['insist'])) {
                        $arguments[] = $options['insist'];
                    } else {
                        $arguments[] = false;
                    }
                    if (isset($options['login_method'])) {
                        $arguments[] = $options['login_method'];
                    } else {
                        $arguments[] = 'AMQPLAIN';
                    }
                    if (isset($options['login_response'])) {
                        $arguments[] = $options['login_response'];
                    } else {
                        $arguments[] = null;
                    }
                    if (isset($options['locale'])) {
                        $arguments[] = $options['locale'];
                    } else {
                        $arguments[] = 'en_US';
                    }
                    if (isset($options['connection_timeout'])) {
                        $arguments[] = $options['connection_timeout'];
                    } else {
                        $arguments[] = 3.0;
                    }
                    if (isset($options['read_write_timeout'])) {
                        $arguments[] = $options['read_write_timeout'];
                    } else {
                        $arguments[] = 3.0;
                    }
                    if (isset($options['context'])) {
                        $arguments[] = $options['context'];
                    } else {
                        $arguments[] = null;
                    }
                    if (isset($options['keepalive'])) {
                        $arguments[] = $options['keepalive'];
                    } else {
                        $arguments[] = false;
                    }
                    if (isset($options['heartbeat'])) {
                        $arguments[] = $options['heartbeat'];
                    } else {
                        $arguments[] = 0;
                    }
                }
            }
            $definition = new Definition($class, $arguments);
            $container->setDefinition('dtc_queue.rabbit_mq', $definition);
            $definition = $container->getDefinition('dtc_queue.job_manager.rabbit_mq');
            $definition->addMethodCall('setAMQPConnection', [new Reference('dtc_queue.rabbit_mq')]);
            $definition->addMethodCall('setQueueArgs', array_values($rabbitMqConfig['queue_args']));
            $definition->addMethodCall('setExchangeArgs', array_values($rabbitMqConfig['exchange_args']));
        }
    }

    /**
     * Determines the job class based on the queue manager type.
     *
     * @param ContainerBuilder $container
     *
     * @return mixed|string
     *
     * @throws \Exception
     */
    public function getJobClass(ContainerBuilder $container)
    {
        $jobClass = $container->getParameter('dtc_queue.class_job');
        if (!$jobClass) {
            switch ($defaultType = $container->getParameter('dtc_queue.default_manager')) {
                case 'mongodb':
                    $jobClass = 'Dtc\\QueueBundle\\Document\\Job';
                    break;
                case 'beanstalkd':
                    $jobClass = 'Dtc\\QueueBundle\\Beanstalkd\\Job';
                    break;
                case 'rabbit_mq':
                    $jobClass = 'Dtc\\QueueBundle\\RabbitMQ\\Job';
                    break;
                case 'orm':
                    $jobClass = 'Dtc\\QueueBundle\\Entity\\Job';
                    break;
                default:
                    throw new \Exception("Unknown default_manager type $defaultType - please specify a Job class in the 'class' configuration parameter");
            }
        }

        if (!class_exists($jobClass)) {
            throw new \Exception("Can't find Job class $jobClass");
        }

        $test = new $jobClass();
        if (!$test instanceof Job) {
            throw new \Exception("$jobClass must be instance of (or derived from) Dtc\\QueueBundle\\Model\\Job");
        }

        return $jobClass;
    }

    public function getRunClass(ContainerBuilder $container)
    {
        $runClass = $container->hasParameter('dtc_queue.class_run') ? $container->getParameter('dtc_queue.class_run') : null;
        if (!$runClass) {
            switch ($defaultType = $container->getParameter('dtc_queue.default_manager')) {
                case 'mongodb':
                    $runClass = 'Dtc\\QueueBundle\\Document\\Run';
                    break;
                case 'orm':
                    $runClass = 'Dtc\\QueueBundle\\Entity\\Run';
                    break;
                default:
                    $runClass = 'Dtc\\QueueBundle\\Model\\Run';
            }
        }

        if (isset($runClass)) {
            if (!class_exists($runClass)) {
                throw new \Exception("Can't find Run class $runClass");
            }
        }

        $test = new $runClass();
        if (!$test instanceof Run) {
            throw new \Exception("$runClass must be instance of (or derived from) Dtc\\QueueBundle\\Model\\Run");
        }

        return $runClass;
    }

    public function getRunArchiveClass(ContainerBuilder $container)
    {
        $runArchiveClass = $container->hasParameter('dtc_queue.class_run_archive') ? $container->getParameter('dtc_queue.class_run_archive') : null;
        if (!$runArchiveClass) {
            switch ($defaultType = $container->getParameter('dtc_queue.default_manager')) {
                case 'mongodb':
                    $runArchiveClass = 'Dtc\\QueueBundle\\Document\\RunArchive';
                    break;
                case 'orm':
                    $runArchiveClass = 'Dtc\\QueueBundle\\Entity\\RunArchive';
                    break;
                default:
                    $runArchiveClass = 'Dtc\\QueueBundle\\Model\\Run';
            }
        }

        if (isset($runArchiveClass)) {
            if (!class_exists($runArchiveClass)) {
                throw new \Exception("Can't find RunArchive class $runArchiveClass");
            }
        }

        $test = new $runArchiveClass();
        if (!$test instanceof Run) {
            throw new \Exception("$runArchiveClass must be instance of (or derived from) Dtc\\QueueBundle\\Model\\Run");
        }

        return $runArchiveClass;
    }

    /**
     * Determines the job class based on the queue manager type.
     *
     * @param ContainerBuilder $container
     *
     * @return mixed|string
     *
     * @throws \Exception
     */
    public function getJobClassArchive(ContainerBuilder $container)
    {
        $jobArchiveClass = $container->getParameter('dtc_queue.class_job_archive');
        if (!$jobArchiveClass) {
            switch ($defaultType = $container->getParameter('dtc_queue.default_manager')) {
                case 'mongodb':
                    $jobArchiveClass = 'Dtc\\QueueBundle\\Document\\JobArchive';
                    break;
                case 'orm':
                    $jobArchiveClass = 'Dtc\\QueueBundle\\Entity\\JobArchive';
                    break;
            }
        }

        if ($jobArchiveClass && !class_exists($jobArchiveClass)) {
            throw new \Exception("Can't find JobArchive class $jobArchiveClass");
        }

        if ($jobArchiveClass) {
            $test = new $jobArchiveClass();
            if (!$test instanceof Job) {
                throw new \Exception("$jobArchiveClass must be instance of (or derived from) Dtc\\QueueBundle\\Model\\Job");
            }
        }

        return $jobArchiveClass;
    }
}
