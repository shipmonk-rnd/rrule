<?php declare(strict_types = 1);

namespace ShipMonk\RRuleTests\Type\Data;

use DateTimeImmutable;
use ShipMonk\RRule\CalendarDate;
use ShipMonk\RRule\FloatingDateTime;
use ShipMonk\RRule\Frequency;
use ShipMonk\RRule\Recurrence;
use ShipMonk\RRule\RecurrenceRule;
use function PHPStan\Testing\assertType;

function instants(DateTimeImmutable $start, RecurrenceRule $rule): void
{
    $recurrence = Recurrence::of($start, $rule);
    assertType('ShipMonk\RRule\Recurrence<DateTimeImmutable>', $recurrence);
    assertType('DateTimeImmutable|null', $recurrence->first());
    assertType('DateTimeImmutable|null', $recurrence->firstAfter(new DateTimeImmutable()));
    assertType('DateTimeImmutable|null', $recurrence->lastBefore(new DateTimeImmutable()));
    assertType('list<DateTimeImmutable>', $recurrence->take(3));
    assertType('DateTimeImmutable', $recurrence->getStart());
    assertType('ShipMonk\RRule\Recurrence<DateTimeImmutable>', $recurrence->withAddedDates(new DateTimeImmutable()));

    foreach ($recurrence as $key => $occurrence) {
        assertType('int', $key);
        assertType('DateTimeImmutable', $occurrence);
    }

    foreach ($recurrence->between(new DateTimeImmutable(), new DateTimeImmutable()) as $occurrence) {
        assertType('DateTimeImmutable', $occurrence);
    }
}

function dates(CalendarDate $start): void
{
    $recurrence = Recurrence::of($start, new RecurrenceRule(Frequency::Daily));
    assertType('ShipMonk\RRule\Recurrence<ShipMonk\RRule\CalendarDate>', $recurrence);
    assertType('ShipMonk\RRule\CalendarDate|null', $recurrence->firstAfter($start));
    assertType('list<ShipMonk\RRule\CalendarDate>', $recurrence->withExcludedDates($start)->getExcludedDates());

    foreach ($recurrence as $occurrence) {
        assertType('ShipMonk\RRule\CalendarDate', $occurrence);
    }
}

function floating(FloatingDateTime $start): void
{
    $recurrence = Recurrence::of($start)->withDtStartPolicy(\ShipMonk\RRule\DtStartPolicy::OnlyIfMatching);
    assertType('ShipMonk\RRule\Recurrence<ShipMonk\RRule\FloatingDateTime>', $recurrence);
    assertType('ShipMonk\RRule\FloatingDateTime|null', $recurrence->lastBefore($start, inclusive: true));
}

function parsed(string $text): void
{
    $recurrence = Recurrence::fromString($text);
    assertType('ShipMonk\RRule\Recurrence<DateTimeImmutable>|ShipMonk\RRule\Recurrence<ShipMonk\RRule\CalendarDate>|ShipMonk\RRule\Recurrence<ShipMonk\RRule\FloatingDateTime>', $recurrence);
}

function rule(): void
{
    $rule = RecurrenceRule::fromString('FREQ=DAILY');
    assertType('list<int<0, 60>>', $rule->bySecond);
    assertType('list<int<-31, -1>|int<1, 31>>', $rule->byMonthDay);
    assertType('list<ShipMonk\RRule\WeekdayNum>', $rule->byDay);
    assertType('int<1, max>', $rule->interval);
    assertType('ShipMonk\RRule\Count|ShipMonk\RRule\Until|null', $rule->end);
}
