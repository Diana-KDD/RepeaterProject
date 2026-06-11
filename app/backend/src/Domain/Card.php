<?php

declare(strict_types=1);

namespace Recall\Domain;

use DateTimeImmutable;
use Recall\Domain\ValueObject\CardId;
use Recall\Domain\ValueObject\CardText;
use Recall\Domain\ValueObject\Day;
use Recall\Domain\ValueObject\Ease;
use Recall\Domain\ValueObject\Interval;
use Recall\Domain\ValueObject\NoteId;
use Recall\Domain\ValueObject\ReviewId;

/** Карточка с текущим расписанием повторения. */
class Card
{
    public function __construct(
        public CardId $id,
        public NoteId $noteId,
        public CardText $front,
        public CardText $back,
        public Ease $ease,
        public Interval $interval,
        public Day $due,
    ) {}

    public static function create(NoteId $noteId, CardText $front, CardText $back, DateTimeImmutable $now): self
    {
        return new self(CardId::generate(), $noteId, $front, $back, Ease::default(), Interval::none(), Day::today($now));
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->id->createdAt();
    }

    public function isDue(Day $today): bool
    {
        return $this->due->isOnOrBefore($today);
    }

    /**
     * Оценить карточку: пересчитать лёгкость и интервал по упрощённому SM-2,
     * сдвинуть дату показа и вернуть запись о повторении.
     */
    public function grade(Grade $grade, DateTimeImmutable $now): Review
    {
        switch ($grade) {
            case Grade::Again:
                $this->ease = $this->ease->loweredBy(0.20);
                $this->interval = Interval::none();
                break;
            case Grade::Hard:
                $this->ease = $this->ease->loweredBy(0.15);
                $this->interval = $this->interval->scaledBy(1.2)->atLeast(1);
                break;
            case Grade::Good:
                $this->interval = $this->interval->isNone()
                    ? Interval::ofDays(1)
                    : $this->interval->scaledBy($this->ease->value);
                break;
            case Grade::Easy:
                $this->ease = $this->ease->raisedBy(0.15);
                $this->interval = $this->interval->isNone()
                    ? Interval::ofDays(3)
                    : $this->interval->scaledBy($this->ease->value * 1.3);
                break;
        }

        $this->due = Day::today($now)->plusDays($this->interval);

        return new Review(ReviewId::generate(), $this->id, $grade, $this->interval, $this->ease, $this->due);
    }
}
