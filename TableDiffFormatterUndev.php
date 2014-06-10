<?php

class TableDiffFormatterUndev extends TableDiffFormatter
{
	/**
	 * HTML-escape parameter before calling this
	 * @param $line
	 * @return string
	 */
	function addedLine($line)
	{
		return $this->wrapLine('−', 'diff-deletedline', $line);
	}

	/**
	 * HTML-escape parameter before calling this
	 * @param $line
	 * @return string
	 */
	function deletedLine($line)
	{
		return $this->wrapLine('+', 'diff-addedline', $line);
	}
} 