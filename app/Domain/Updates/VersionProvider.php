<?php
namespace App\Domain\Updates;
interface VersionProvider { public function fetch(int $appId):array; }
