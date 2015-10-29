<?php

namespace FintechFab\LumenQueueRabbitMQ\Queue;

use DateTime;
use FintechFab\LumenQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQQueue extends Queue implements QueueContract
{

    protected $connection;
    protected $channel;

    protected $defaultQueue;
    protected $configQueue;
    protected $configExchange;
    protected $arguments;

    protected static $initedQueues = [];

    /**
     * @param AbstractConnection $amqpConnection
     * @param array $config
     */
    public function __construct(AbstractConnection $amqpConnection, $config)
    {
        $this->connection = $amqpConnection;
        $this->defaultQueue = $config['queue'];
        $this->configQueue = $config['queue_params'];
        $this->configExchange = $config['exchange_params'];

        $this->channel = $this->getChannel();
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string $job
     * @param  mixed $data
     * @param  string $queue
     *
     * @return bool
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, []);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload
     * @param  string $queue
     * @param  array $options
     *
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueueName($queue);
        $declaredQueue = $this->declareQueue($queue, $options);


        // push job to a queue
        $message = new AMQPMessage($payload, [
            'Content-Type' => 'application/json',
            'delivery_mode' => 2,
        ]);

        // push task to a queue
        $this->channel->basic_publish($message, $declaredQueue, $declaredQueue);

        return true;
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTime|int $delay
     * @param  string $job
     * @param  mixed $data
     * @param  string $queue
     *
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $delay]);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     *
     * @return \Illuminate\Queue\Jobs\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueueName($queue);

        // get envelope
        $message = $this->channel->basic_get($queue);

        if ($message instanceof AMQPMessage) {
            return new RabbitMQJob($this->container, $this, $this->channel, $queue, $message);
        }

        return null;
    }

    /**
     * @param string $queue
     *
     * @return string
     */
    private function getQueueName($queue)
    {
        return $queue ?: $this->defaultQueue;
    }

    /**
     * @return AMQPChannel
     */
    private function getChannel()
    {
        return $this->connection->channel();
    }

    /**
     * @param $name
     * @param $options
     * @return string
     */
    private function declareQueue($name, $options)
    {
        $arguments = null;
        $name = $this->getQueueName($name);

        if (isset($options['delay'])) {
            $delay = $this->getSeconds($options['delay']);
            $name .= '_deferred_' . $delay;
            $arguments = new AMQPTable([
                'x-dead-letter-exchange' => $name,
                'x-dead-letter-routing-key' => $name,
                'x-message-ttl' => $delay * 1000,
            ]);
        }

        //avoid redeclaring queue
        if (!in_array($name, self::$initedQueues)) {
            // set flag
            self::$initedQueues[] = $name;

            // declare exchange
            $this->channel->exchange_declare(
                $name,
                $this->configExchange['type'],
                $this->configExchange['passive'],
                $this->configExchange['durable'],
                $this->configExchange['auto_delete']
            );

            // declare queue
            $this->channel->queue_declare(
                $name,
                $this->configQueue['passive'],
                $this->configQueue['durable'],
                $this->configQueue['exclusive'],
                $this->configQueue['auto_delete'],
                false,
                $arguments
            );

            // bind queue to the exchange
            $this->channel->queue_bind($name, $name, $name);
        }

        return $name;
    }

}
