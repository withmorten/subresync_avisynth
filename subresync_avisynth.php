<?php
const CRLF = "\r\n";
const TAB = "\t";

function d($var)
{
	var_dump($var);
}

function dd($var)
{
	var_dump($var);
	die();
}

function frameToMs($frame, $fps)
{
	// VirtualDub rounds, AviDemux floors ...

	return floor((1000 / $fps) * $frame);
	// return round((1000 / $fps) * $frame);
	// return ((1000 / $fps) * $frame);
}

function addTimeLineBlock(&$timeLineBlocks, $fps, $from_frames, $sync_frames)
{
	$from = frameToMs($from_frames, $fps);
	$sync = frameToMs($sync_frames, $fps);

	$timeLineBlocks[] = [
		'from' => $from,
		'from_frames' => $from_frames,
		'sync' => $sync,
		'sync_frames' => $sync_frames,
	];

	return count($timeLineBlocks);
}

function parse_avs($input_avs, $inverted)
{
	$timeLineBlocks = [];

	$fps = 0;

	$last_frame = -1;
	$last_frame_count = 0;

	$last_frame_action = "";

	foreach ($input_avs as $line)
	{
		if ($line === "") continue;
		if (str_starts_with($line, "blu=")) continue;

		if (str_contains($line, "AssumeFPS"))
		{
			if ($fps !== 0) echo "AssumeFPS: fps already set, setting again".CRLF;

			$line = str_replace(" ", "", $line);
			$pos = strpos($line, "AssumeFPS") + strlen("AssumeFPS");
			$args = explode(",", substr($line, $pos + 1, -1));

			$numerator = (int)$args[0];
			$denominator = (int)$args[1];

			$fps = $numerator / $denominator;
		}
		else if (str_contains($line, "Trim"))
		{
			if (!empty($timeLineBlocks)) die("Trim: has to be before any Frame commands or cannot be used with BlankClip, error".CRLF);
			if ($fps === 0) die("Trim: AssumeFPS has to be before any commands, error".CRLF);

			$line = str_replace(" ", "", $line);
			$pos = strpos($line, "Trim") + strlen("Trim");
			$args = explode(",", substr($line, $pos + 1, -1));

			$first_frame = (int)$args[0];
			$last_frame = (int)$args[1];

			if ($inverted === false)
			{
				$numTimeLineBlocks = addTimeLineBlock($timeLineBlocks, $fps, 0, -$first_frame);
			}
			else
			{
				$numTimeLineBlocks = addTimeLineBlock($timeLineBlocks, $fps, 0, $first_frame);
			}

			if ($last_frame !== 0) echo "Trim: last_frame set, ignoring".CRLF;
		}
		else if (str_contains($line, "BlankClip"))
		{
			if (!empty($timeLineBlocks)) die("BlankClip: has to be before any Frame commands or cannot be used with Trim, error".CRLF);
			if ($fps === 0) die("BlankClip: AssumeFPS has to be before any commands, error".CRLF);

			$line = str_replace(" ", "", $line);
			$pos = strpos($line, "BlankClip") + strlen("BlankClip");
			$args = explode(",", substr($line, $pos + 1, -1));

			$frames = (int)$args[0];

			if ($inverted === false)
			{
				$numTimeLineBlocks = addTimeLineBlock($timeLineBlocks, $fps, 0, $frames);
			}
			else
			{
				$numTimeLineBlocks = addTimeLineBlock($timeLineBlocks, $fps, 0, -$frames);
			}
		}
		else if (str_contains($line, "DeleteFrame") || str_contains($line, "DuplicateFrame"))
		{
			if ($fps === 0) die("DeleteFrame/DuplicateFrame: AssumeFPS has to be before any commands, error".CRLF);

			if (str_contains($line, "DeleteFrame"))
			{
				$frame_action = "Delete";

				$line = str_replace(" ", "", $line);
				$pos = strpos($line, "DeleteFrame") + strlen("DeleteFrame");
				$args = explode(",", substr($line, $pos + 1, -1));

				$frame = (int)$args[0];
			}
			else if (str_contains($line, "DuplicateFrame"))
			{
				$frame_action = "Duplicate";

				$line = str_replace(" ", "", $line);
				$pos = strpos($line, "DuplicateFrame") + strlen("DuplicateFrame");
				$args = explode(",", substr($line, $pos + 1, -1));

				$frame = (int)$args[0];
			}

			if ($frame_action === $last_frame_action && $frame === $last_frame)
			{
				$last_frame_count++;
			}
			else
			{
				if ($last_frame_action === "Delete")
				{
					if ($inverted === false)
					{
						addTimeLineBlock($timeLineBlocks, $fps, $last_frame, -$last_frame_count);
					}
					else
					{
						addTimeLineBlock($timeLineBlocks, $fps, $last_frame, $last_frame_count);
					}
				}
				else if ($last_frame_action === "Duplicate")
				{
					if ($inverted === false)
					{
						addTimeLineBlock($timeLineBlocks, $fps, $last_frame, $last_frame_count);
					}
					else
					{
						addTimeLineBlock($timeLineBlocks, $fps, $last_frame, -$last_frame_count);
					}
				}

				$last_frame_action = $frame_action;
				$last_frame = $frame;
				$last_frame_count = 1;
			}
		}
		else if (str_contains($line, ", end of "))
		{
			if ($last_frame_action === "Delete")
			{
				if ($inverted === false)
				{
					addTimeLineBlock($timeLineBlocks, $fps, $last_frame, -$last_frame_count);
				}
				else
				{
					addTimeLineBlock($timeLineBlocks, $fps, $last_frame, $last_frame_count);
				}
			}
			else if ($last_frame_action === "Duplicate")
			{
				if ($inverted === false)
				{
					addTimeLineBlock($timeLineBlocks, $fps, $last_frame, $last_frame_count);
				}
				else
				{
					addTimeLineBlock($timeLineBlocks, $fps, $last_frame, -$last_frame_count);
				}
			}

			break;
		}
	}

	return $timeLineBlocks;
}

if ($argc === 1)
{
	die("need first arg input.avs".CRLF);
}

$input_avs = file($argv[1], FILE_IGNORE_NEW_LINES);

// $inverted = true;
$inverted = false;

$timeLineBlocks = parse_avs($input_avs, $inverted);

file_put_contents($argv[1].".json", json_encode($timeLineBlocks));
