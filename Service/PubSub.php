<?php

declare(strict_types=1);

namespace MageOS\AsyncEventsGCP\Service;

use CloudEvents\Serializers\JsonSerializer;
use CloudEvents\Serializers\Normalizers\V1\Normalizer;
use CloudEvents\V1\CloudEventImmutable;
use Exception;
use Google\Cloud\PubSub\PubSubClient;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\ResultInterface;
use MageOS\AsyncEvents\Helper\NotifierResult;
use MageOS\AsyncEvents\Helper\NotifierResultFactory;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use MageOS\AsyncEventsGCP\Model\PubSubConfig;

class PubSub implements NotifierInterface
{
    /**
     * @param NotifierResultFactory $notifierResultFactory
     * @param Normalizer $normalizer
     * @param SerializerInterface $serializer
     * @param PubSubConfig $pubSubConfig
     */
    public function __construct(
        private readonly NotifierResultFactory $notifierResultFactory,
        private readonly Normalizer $normalizer,
        private readonly SerializerInterface $serializer,
        private readonly PubSubConfig $pubSubConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function notify(AsyncEventInterface $asyncEvent, CloudEventImmutable $event): ResultInterface
    {
        /** @var NotifierResult $result */
        $result = $this->notifierResultFactory->create();
        $result->setSubscriptionId($asyncEvent->getSubscriptionId());
        $result->setAsyncEventData($this->normalizer->normalize($event, false));
        $result->setIsSuccessful(false);
        $result->setIsRetryable(false);

        try {
            $pubSub = new PubSubClient([
                'credentials' => $this->pubSubConfig->getAdcPath()
            ]);

            $topic = $pubSub->topic($asyncEvent->getRecipientUrl());

            $messages = $topic->publish([
                'data' => JsonSerializer::create()->serializeStructured($event)
            ]);

            $result->setIsSuccessful(true);
            $result->setResponseData($this->serializer->serialize($messages));
        } catch (Exception $exception) {
            $result->setIsSuccessful(false);
            $result->setIsRetryable(false);
            $result->setResponseData($this->serializer->serialize($exception->getMessage()));
        }

        return $result;
    }
}
