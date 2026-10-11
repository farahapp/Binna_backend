<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkEvaluation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EvaluationStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NetworkEvaluation $evaluation,
    ) {}
}
