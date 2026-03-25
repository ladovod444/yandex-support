<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Yandex\Support\Messenger\YandexReview;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Products\Review\Repository\FindExistByExternal\FindExistByExternalInterface;
use BaksDev\Yandex\Market\Repository\YaMarketTokensByProfile\YaMarketTokensByProfileInterface;
use BaksDev\Yandex\Support\Api\Review\Get\GetListReviews\YandexGetListReviewsRequest;
use BaksDev\Yandex\Support\Api\Review\Get\GetListReviews\YandexReviewDTO;
use BaksDev\Yandex\Support\Messenger\Schedules\YandexSupportNewReview\NewYandexSupportReviewMessage;
use BaksDev\Yandex\Support\Schedule\YandexGetNewReview\YandexGetNewReviewSchedule;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


/**
 * Подготовка данных для создания product reviews на основе Yandex Reviews
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler]
final readonly class YandexReviewHandler
{

    public function __construct(
        private YandexGetListReviewsRequest $yandexGetListReviewsRequest,
        private YaMarketTokensByProfileInterface $YaMarketTokensByProfile,
        private DeduplicatorInterface $deduplicator,
        private FindExistByExternalInterface $existByExternal,
        private MessageDispatchInterface $messageDispatch,
    ) {}

    public function __invoke(NewYandexSupportReviewMessage $message): void
    {

        $isExecuted = $this
            ->deduplicator
            ->expiresAfter('1 minute')
            ->deduplication([$message->getProfile(), self::class]);

        if($isExecuted->isExecuted())
        {
            return;
        }

        $isExecuted->save();


        /** Получаем все токены профиля */

        $tokensByProfile = $this->YaMarketTokensByProfile
            ->findAll($message->getProfile());

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }


        /** Итерируемся по всем токенам */

        foreach($tokensByProfile as $YaMarketTokenUid)
        {

            /**
             * Получить все непрочитанные yandex отзывы
             */

            $from = new DateTimeImmutable()
                ->setTimezone(new DateTimeZone('GMT'))

                // периодичность scheduler
                ->sub(DateInterval::createFromDateString(YandexGetNewReviewSchedule::INTERVAL))

                // запас на runtime
                ->sub(DateInterval::createFromDateString('1 hour'));
//                ->sub(DateInterval::createFromDateString('10 days')); // TODO для проверки сделать за 10 дней?

            /** @var Generator $reviews */
            $reviews = $this->yandexGetListReviewsRequest
                ->forTokenIdentifier($YaMarketTokenUid)
                ->dateFrom($from)
                ->findAll();


            /* Если нет отзывов - прервать работу */
            if(false === $reviews->valid())
            {
                return;
            }


            /* Итерируемся по полученным отзывам */
            /** @var YandexReviewDTO $review */
            foreach($reviews as $review)
            {

                /* Проверка на существование отзыва по внешнему Id */

                $reviewExists = $this->existByExternal
                    ->external($review->getReviewId())
                    ->exist();

                if(true === $reviewExists)
                {
                    continue;
                }


                /* Сообщение для отзыва создается только, если в yandex отзыве есть текст комментария */
                if(empty($review->getText()) || $review->getText() === 'Отзыв без комментария пользователя')
                {
                    continue;
                }


                /* Создать сообщение */
                $YandexReviewMessage = new YandexReviewMessage(
                    article: $review->getArticle(),
                    rating: $review->getRating(),
                    text: $review->getText(),
                    token: $YaMarketTokenUid->getValue(),
                    external: $review->getReviewId(),
                    profile: $message->getProfile(),
                    author: $review->getAuthor(),
                );


                $this->messageDispatch->dispatch(
                    message: $YandexReviewMessage,
                    stamps: [new MessageDelay(sprintf('%s seconds', 1))],
                    transport: 'yandex-support-low',
                );

            }

        }

    }
}
