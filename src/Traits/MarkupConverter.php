<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.2.0
 * @since    0.2.0
 *
 * @author  Jonathan Jin <jonathan@tentwentyfour.lu>
 * @author  David Raison <david@tentwentyfour.lu>
 *
 * (c) TenTwentyFour
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim\Traits;

trait MarkupConverter
{
    /**
     * Tries to detect whether the content in Redmine is using textile or markdown
     * and then converts some markup (if textile) or just passes it on.
     *
     * This is a really naive and inefficient approach which could be improved.
     *
     * @todo Support external links
     *
     * @param  String $text Input text
     *
     * @return String Converted text
     */
    public function textileToMarkdown($text)
    {
        return str_replace(
            ["\r", 'h1.', 'h2.', 'h3.', 'h4.', '<pre>', '</pre>', '@', '*', '_'],
            ['', '#', '##', '###', '####', '```', '```', '`', '**', '//'],
            trim($text)
        );
    }

    /**
     * Convert some text into a "quote" by prefixing it with "> "
     * and replacing subsequent newlines with "> ".
     *
     * @param  string $text Original text to be transformed into a quote
     *
     * @return string       Quoted text
     */
    public function convertToQuote($text)
    {
        return sprintf(
            '> %s',
            preg_replace("/[\n\r]/", "\n> ", $text)
        );
    }
}