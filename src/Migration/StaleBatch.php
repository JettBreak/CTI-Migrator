<?php

namespace App\Migration;

/** Live core data changed between validation and approval; the batch is not applied. */
final class StaleBatch extends \RuntimeException
{
}
