<?php

namespace App\Enums;

/**
 * What a wrong guess at a product choice could touch (§7). The planner tags
 * each question with these, and config/builder.php decides which of them are
 * worth stopping to ask the owner about.
 */
enum Consequence: string
{
    case Money = 'money';

    /** Who may see or do what. */
    case Access = 'access';

    case DataLoss = 'data_loss';

    /** What is stored and how it relates. */
    case DataShape = 'data_shape';

    case OutsideServices = 'outside_services';

    case Legal = 'legal';

    /** A major way the business works. */
    case MajorWorkflow = 'major_workflow';

    /**
     * Get how serious a wrong guess here is, 0 the most: what cannot be got
     * back comes before what can be put right.
     */
    public function seriousness(): int
    {
        return match ($this) {
            self::DataLoss => 0,
            self::Money => 1,
            self::Legal => 2,
            self::Access => 3,
            self::OutsideServices => 4,
            self::MajorWorkflow => 5,
            self::DataShape => 6,
        };
    }
}
