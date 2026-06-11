<?php

declare(strict_types=1);

namespace Recall\Http\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Recall\Domain\Stats;
use Recall\Domain\ValueObject\Day;
use Recall\Domain\ValueObject\Interval;
use Recall\Http\Json;
use Recall\Http\Serializer;
use Recall\Infrastructure\Persistence\CardRepository;
use Recall\Infrastructure\Persistence\ReviewRepository;

final readonly class StatsController
{
    public function __construct(
        private CardRepository $cards,
        private ReviewRepository $reviews,
        private Serializer $serializer,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $now = new DateTimeImmutable('now');
        $today = Day::today($now);
        $dueToday = count($this->cards->dueOn($today));

        $weekLater = $today->plusDays(Interval::ofDays(7));
        $dueWeek = count($this->cards->dueOn($weekLater));

        $stats = new Stats(dueToday: $dueToday, dueWeek: $dueWeek, streak: $this->calculateStreak());
        return Json::write($response, $this->serializer->serialize($stats));

    }

    // Подсчет серии. +1 при повторении карточки. 0 при пропуске повтора хоть одной карточки в день
    private function calculateStreak(): int
    {
        $dates = $this->getUniqueActivityDates();

        if ($dates === []) {
            return 0;
        }

        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('now'))->modify('-1 day')->format('Y-m-d');

        $firstDate = $dates[0];

        if ($firstDate === $yesterday) {
            return $this->countStreakFromDate($dates, $yesterday);
        }

        if ($firstDate === $today) {
            return $this->countStreakFromDate($dates, $today);
        }

        return 0;
    }

    /**
     * @return array<string>
    */
    private function getUniqueActivityDates(): array
    {
        $reviews = $this->reviews->all();
        $dates = [];
        foreach ($reviews as $review) {
            $date = $review->createdAt()->format('Y-m-d');
            $dates[$date] = true;
        }
        $result = array_keys($dates);
        rsort($result);
        return $result;
    }

    /**
     * @param array<string> $dates
     */
    private function countStreakFromDate(array $dates, string $startDate): int
    {
        $streak = 1;
        $expectedDate = new DateTimeImmutable($startDate);

        $datesCount = count($dates);
        for ($i = 1; $i < $datesCount; $i++) {
            $expectedDate = $expectedDate->modify('-1 day');
            $currentDate = new DateTimeImmutable($dates[$i]);

            if ($currentDate->format('Y-m-d') === $expectedDate->format('Y-m-d')) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}
