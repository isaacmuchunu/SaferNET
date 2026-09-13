<?php

namespace App\Services\Ai;

use App\Enums\EnforcementAction;
use App\Enums\Severity;

/**
 * A classifier's opinion about one request. Never trusted as it arrives: it is
 * coerced into this shape or rejected outright, so a malformed answer degrades
 * to the deterministic rules instead of corrupting a filtering decision.
 */
final readonly class ContentAssessment
{
    public function __construct(
        public string $category,
        public int $riskScore,
        public Severity $severity,
        public EnforcementAction $action,
        public string $rationale,
        public string $provider,
        public string $model,
    ) {}

    /** @var list<string> */
    public const CATEGORIES = [
        'Self-Harm',
        'School Violence',
        'Cyberbullying',
        'Explicit/Adult',
        'Circumvention/VPN',
        'Controlled Substances',
        'Gambling',
        'Safe Educational',
        'Other',
    ];

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromModelOutput(array $raw, string $provider, string $model): ?self
    {
        $score = filter_var($raw['riskScore'] ?? null, FILTER_VALIDATE_INT);

        if ($score === false) {
            return null;
        }

        $score = max(0, min(100, $score));
        $category = in_array($raw['category'] ?? null, self::CATEGORIES, true) ? $raw['category'] : 'Other';
        $severity = Severity::tryFrom((string) ($raw['riskSeverity'] ?? '')) ?? self::severityFromScore($score);

        $action = EnforcementAction::tryFrom((string) ($raw['action'] ?? ''))
            ?? match ($severity) {
                Severity::Critical, Severity::High => EnforcementAction::Block,
                Severity::Medium => EnforcementAction::Warn,
                Severity::Low => EnforcementAction::Allow,
            };

        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return new self(
            category: $category,
            riskScore: $score,
            severity: $severity,
            action: $action,
            rationale: $rationale === '' ? 'No rationale was supplied by the classifier.' : mb_substr($rationale, 0, 300),
            provider: $provider,
            model: $model,
        );
    }

    public static function severityFromScore(int $score): Severity
    {
        return match (true) {
            $score >= 80 => Severity::Critical,
            $score >= 50 => Severity::High,
            $score >= 25 => Severity::Medium,
            default => Severity::Low,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'risk_score' => $this->riskScore,
            'severity' => $this->severity->value,
            'action' => $this->action->value,
            'rationale' => $this->rationale,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}
