<?php

namespace Venusian\Build\Sources;

use RuntimeException;

/** The server answered 404: there is nothing under that name, as opposed to no answer at all. */
final class NotFound extends RuntimeException {}
