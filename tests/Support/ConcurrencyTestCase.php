<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Concurrency tests run real parallel processes, which must see committed data, so
 * they truncate between tests instead of wrapping each test in a transaction.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * The sequencer's single counter row is schema, not test data.
     *
     * @var list<string>
     */
    protected $exceptTables = ['activity_sequencer'];
}
