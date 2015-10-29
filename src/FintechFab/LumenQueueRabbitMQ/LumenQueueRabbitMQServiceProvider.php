<?php

namespace FintechFab\LumenQueueRabbitMQ;

use FintechFab\LumenQueueRabbitMQ\Queue\Connectors\RabbitMQConnector;
use Illuminate\Support\ServiceProvider;

class LumenQueueRabbitMQServiceProvider extends ServiceProvider
{

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        /**
         * @var \Illuminate\Queue\QueueManager $manager
         */
        $manager = $this->app['queue'];
        $manager->addConnector('rabbitmq', function () {
            return new RabbitMQConnector;
        });
    }
}
