<?php

namespace App\Domains\Reports;

final readonly class Document
{
    /** @param array<string,int> $metrics
     * @param array<string,array{label:string,type:string}> $columns
     * @param list<array<string,int|string>> $rows */
    public function __construct(public string $title, public string $note, public Period $period, public array $metrics, public array $columns, public array $rows) {}
}
