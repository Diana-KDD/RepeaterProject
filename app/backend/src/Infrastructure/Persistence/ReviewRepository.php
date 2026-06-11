<?php

declare(strict_types=1);

namespace Recall\Infrastructure\Persistence;

use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Select;
use Recall\Domain\Review;

/** Доступ к повторениям через Cycle ORM. */
final readonly class ReviewRepository
{
    public function __construct(private ORMInterface $orm) {}

    public function save(Review $review): void
    {
        (new EntityManager($this->orm))->persist($review)->run();
    }

    /**
     * @return array<int, Review>
     */
    public function all(): array
    {
        $result = (new Select($this->orm, Review::class))->fetchAll();
        $reviews = [];

        foreach ($result as $item) {
            if ($item instanceof Review) {
                $reviews[] = $item;
            }
        }

        return $reviews;
    }
}
