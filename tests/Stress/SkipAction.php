<?php

namespace Tests\Stress;

/** Thrown while planning an action that would only re-trigger an already known finding. */
final class SkipAction extends \RuntimeException
{
}
