<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.1.0 Short Circuit
 * @since    0.1.0 Short Circuit
 *
  * @author  David Raison <david@tentwentyfour.lu>
 *
 * (c) TenTwentyFour
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim\Exception;

class NoIssuesFoundException extends \RuntimeException
{
    public function __construct($message, $code = null)
    {
        parent::__construct($message, $code);
    }
}