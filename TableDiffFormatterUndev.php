<?php

class TableDiffFormatterUndev extends TableDiffFormatter
{
	/**
	 * @param $marker
	 * @param $class
	 * @param $line
	 * @return string
	 */
	protected function wrapLine($marker, $class, $line)
	{
		if ($line !== '') {
			// The <div> wrapper is needed for 'overflow: auto' style to scroll properly
			$line = Xml::tags('div', null, $this->escapeWhiteSpace($line));
		}
		return "<td class='diff-marker'>$marker</td><td class='$class'>$line</td>";
	}

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