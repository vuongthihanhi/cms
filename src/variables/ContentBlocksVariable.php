<?php
namespace Blocks;

/**
 * Content block functions
 */
class ContentBlocksVariable
{
	/**
	 * Returns all blocktypes.
	 * @return array
	 */
	public function blocktypes()
	{
		return blx()->blocks->getBlockTypes();
	}
}
