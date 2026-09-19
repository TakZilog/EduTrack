<?php
/** The building graph for the walkthrough and room list. Students only. */
declare(strict_types=1);
require __DIR__ . "/tour-gate.php";
require_tour_access();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: private, no-store");
readfile(__DIR__ . "/../assets/nodes/nodes-edges.json");
