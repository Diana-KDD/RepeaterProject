<?php

declare(strict_types=1);

namespace Recall\Http\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Recall\Domain\Grade;
use Recall\Domain\ValueObject\CardId;
use Recall\Domain\ValueObject\Day;
use Recall\Http\Json;
use Recall\Http\Serializer;
use Recall\Infrastructure\Persistence\CardRepository;
use Recall\Infrastructure\Persistence\ReviewRepository;

final readonly class ReviewsController
{
    public function __construct(
        private CardRepository $cards,
        private ReviewRepository $reviews,
        private Serializer $serializer,
        private DateTimeImmutable $now,
    ) {}

    /** Карточки, которые пора повторить. */
    public function queue(Request $request, Response $response): Response
    {
        $due = $this->cards->dueOn(Day::today($this->now));

        return Json::write($response, array_map($this->serializer->serialize(...), $due));
    }

    /**
     * Оценить карточку и запланировать следующий показ.
     *
     * @param array<array-key, mixed> $args
     */
    public function grade(Request $request, Response $response, array $args): Response
    {
        $raw = $args['id'] ?? null;
        $card = null;
        if (is_string($raw)) {
            try {
                $card = $this->cards->find(CardId::fromString($raw));
            } catch (InvalidArgumentException) {
                $card = null;
            }
        }
        if ($card === null) {
            return Json::error($response, 'card not found', 404);
        }

        $body = $request->getParsedBody();
        $gradeRaw = is_array($body) ? ($body['grade'] ?? null) : null;
        $grade = is_string($gradeRaw) ? Grade::tryFrom($gradeRaw) : null;
        if ($grade === null) {
            return Json::error(
                $response,
                'grade must be one of: ' . implode(', ', Grade::values()),
                422,
                ['grade' => 'допустимые значения: ' . implode(', ', Grade::values())],
            );
        }

        $review = $card->grade($grade, $this->now);
        $this->cards->save($card);
        $this->reviews->save($review);

        return Json::write($response, $this->serializer->serialize($review), 201);
    }
}
