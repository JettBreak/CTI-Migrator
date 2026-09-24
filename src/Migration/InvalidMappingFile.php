<?php

namespace App\Migration;

/** The uploaded file cannot be read as a mapping file at all (as opposed to individual rows failing validation). */
final class InvalidMappingFile extends \RuntimeException
{
}
