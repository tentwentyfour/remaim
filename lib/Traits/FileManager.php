<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.0.1 First public release
 * @since    0.0.1 First public release
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

trait FileManager
{
    public function uploadFiles($issue, $policy)
    {
        return array_map(function ($attachment) use ($policy) {
            $url = preg_replace(
                '/http(s?):\/\//',
                sprintf(
                    'https://%s:%s@',
                    $this->config['redmine']['user'],
                    $this->config['redmine']['password']
                ),
                $attachment['content_url']
            );

            $encoded = base64_encode(file_get_contents($url));
            $file_phid = $this->conduit->callMethodSynchronous(
                'file.upload',
                [
                    'name' => $attachment['filename'],
                    'data_base64' => $encoded,
                    'viewPolicy' => $policy,
                ]
            );
            $result = $this->fetchFileInfo($file_phid);
            return sprintf('{%s}', $result['objectName']);
        }, $issue['attachments']);
    }

    public function fetchFileInfo($file_phid)
    {
        return $result = $this->conduit->callMethodSynchronous(
            'file.info',
            [
                'phid' => $file_phid,
            ]
        );
    }
}