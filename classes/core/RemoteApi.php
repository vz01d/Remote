<?php
/**
 * Remote
 *  
 * @category Multisite
 * @package  Remote
 * @author   Sebo <sebo@42geeks.gg>
 * @license  GPLv2 https://opensource.org/licenses/gpl-2.0.php
 * @link     https://42geeks.gg/
 */
namespace remote\core;

// octopus & hexagon
use remote\core\crypto\Octopus;

require_once ABSPATH . 'wp-admin/includes/file.php';

/**
 * Class RemoteApi - launch requests to remote site accessing
 * Fetcher wp-json API
 * 
 * @category Multisite
 * @package  Remote
 * @author   Sebo <sebo@42geeks.gg>
 * @license  GPLv2 https://opensource.org/licenses/gpl-2.0.php
 * @link     https://42geeks.gg/
 */
class RemoteApi extends Octopus
{
	const REMOTE_END = 'wp-json/fetcher/v2/remote/';

	/**
	 * The remote sites base url
	 */
	protected $baseUrl = '';

    /**
     * Empty
     */
    public function __construct()
	{
	}

	/**
	 * Set url for the api only once when the
	 * post is loaded
	 * 
	 * @param string $url - the url
	 * 
	 * @return void
	 */
	public function setUrl(string $url): void
	{
		$this->baseUrl = $url;
    }
    
    /**
     * Sends a page to the remote site with all
     * required meta (including images) attached
     * images will be sent as base64
     * 
     * @param string $secret - the secret for the site
     * @param int $postId - the local post id
	 * @param int $siteId - the id of the site
     * 
     * @return int - the remote post
     */
    public function sendPostToRemote(string $secret, int $postId, int $siteId): int
    {
		$endpoint = 'updatepost';
        // TODO: abstract this part to separate function/class
        // move handshake to octopus
        // add new function sendMessage to Octopus
        $octopus = new Octopus($secret, true);
        [$mac, $msg, $saltVector] = $octopus->getHandshakeData($endpoint);
        $res = $this->_handshake($mac, $msg, $saltVector);
        if (false === $res->error) {
            // get post data
            $post = get_post($postId);
            $title = $post->post_title;
			$content = $post->post_content;
			$status = $post->post_status;
            $isHome = get_field('is_home', $postId);
            $postType = $post->post_type;

            // get featured image
			$featuredImageUrl = get_the_post_thumbnail_url($postId, 'full');

            // get attachments in content
            // TODO replace all images with a unique hash matching the image string
            // from file_get_contents md5
            $attachments = null;
            preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $content, $attachments);
        
            $inContentImages = [];
            if (isset($attachments[1]) && is_array($attachments[1])) {
                foreach ($attachments[1] as $attachmentUrl) {
                    // get file contents
                    $file = file_get_contents($attachmentUrl);
                    $b64 = base64_encode($file);

					$mime = 'text/plain';
					$tmpFile = download_url($attachmentUrl, 3);
					if (! is_wp_error($tmpFile)) {
						$mime = mime_content_type($tmpFile);
					}

                    // update content with md5 of b64
                    $md5 = md5($b64);
                    $content = str_replace($attachmentUrl, $md5, $content);

                    $inContentImages[] = [
						'url'  => $attachmentUrl,
						'hash' => $md5,
						'mime' => $mime
					];
				}
			}
			
			$uniqueId = get_post_meta($siteId, 'uniqueId_'.$postId, true);
			if ('' == $uniqueId) {
				$uniqueId = md5($siteId.$postId.time());
				update_post_meta($siteId, 'uniqueId_'.$postId, $uniqueId);
			}

            // request data
            $rqData = [
                'request' => self::rhashRoute($endpoint),
                'requestData' => [
                    'title'			  => $title,
                    'content'         => $content,
                    'isHome'          => $isHome,
                    'postType'        => $postType,
                    'featuredImage'   => $featuredImageUrl,
					'inContentImages' => $inContentImages,
					'status'		  => $status,
					'uniqueId'		  => $uniqueId
                ]
            ];
            $ciphertext = $octopus->generateCiphertext(json_encode($rqData, true));
            $mac2 = $octopus->sign2ndRequest($ciphertext);
            $remoteResponse = $this->_sendRequestToSite(
				[
					'mac' => $mac2,
					'msg' => $ciphertext
				],
				$endpoint
			);
            return isset($remoteResponse->rid) ? $remoteResponse->rid : 0;
        }

        return null === $res->error ? false : $res->error;
    }

	/**
     * Connect a Fetcher Site to this remote Network
	 * to do that a few things will happen:
	 * 
	 * 1) a secret key is generated derived from secret
	 * 2) a cryptographic key is crafted using halite
	 * 3) the secret key will be sent to the remote site using handshake
	 * where it's stored in the database, this secret key will
	 * contain the endpoint beeing opened up. The key will live their
	 * for only one request, after that the endpoint is removed
	 * 4) the original intended request containing actual data is sent signed
	 * with the handshake secret and hmac with the remote secret
	 * 
	 * @param string $secret - the secret provided (needs to match the secret on the fetcher site)
	 * @param int $site_id - the site_id to connect to
	 * 
     * @return bool - wether the connect succeeded or not
     */
    public function connect(string $secret, int $site_id): bool
    {
		$endpoint = 'connect';

        // handshake -> TODO: abstract
        $octopus = new Octopus($secret, true);
        [$mac, $msg, $saltVector] = $octopus->getHandshakeData($endpoint);
        $res = $this->_handshake($mac, $msg, $saltVector);
        if (false === $res->error) {
            $showHeader        = get_field('show_header', $site_id);
            $showNavigation    = get_field('show_navigation', $site_id);
            $showSlider        = get_field('show_slider', $site_id);
            $showFeaturedImage = get_field('show_featured_image', $site_id);
            $siteLogoUrl = false === isset($siteLogo['url']) ? '' : $siteLogo['url'];

            // request data
            $rqData = [
                'request' => self::rhashRoute($endpoint),
                'requestData' => [
                    $showHeader,
                    $showNavigation,
                    $showSlider,
                    $showFeaturedImage,
                    $siteLogoUrl
                ]
            ];
            $ciphertext = $octopus->generateCiphertext(json_encode($rqData, true));
			$mac2 = $octopus->sign2ndRequest($ciphertext);
            $remoteResponse = $this->_sendRequestToSite(
				[
					'mac' => $mac2,
					'msg' => $ciphertext
				],
				$endpoint
			);
            return !$remoteResponse->error;
        }

        return null === $res->error ? false : $res->error;
    }

    /**
     * Update a sites settings
     * 
     * @param string $secret - the site secret
     * @param int $site_id - the site local id
     */
    public function update(string $secret, int $site_id): void
    {
		$endpoint = 'update';
        // handshake -> TODO: abstract
        $octopus = new Octopus($secret, true);
        [$mac, $msg, $saltVector] = $octopus->getHandshakeData($endpoint);
        $res = $this->_handshake($mac, $msg, $saltVector);
        if (false === $res->error) {
            $showHeader        = get_field('show_header', $site_id);
            $showNavigation    = get_field('show_navigation', $site_id);
            $showSlider        = get_field('show_slider', $site_id);
            $showFeaturedImage = get_field('show_featured_image', $site_id);
            $siteLogo          = get_field('site_logo', $site_id);
            $siteLogoUrl = false === isset($siteLogo['url']) ? '' : $siteLogo['url'];

            // request data
            $rqData = [
                'request' => self::rhashRoute($endpoint),
                'requestData' => [
                    $showHeader,
                    $showNavigation,
                    $showSlider,
                    $showFeaturedImage,
                    $siteLogoUrl
                ]
            ];
            $ciphertext = $octopus->generateCiphertext(json_encode($rqData, true));
            $mac2 = $octopus->sign2ndRequest($ciphertext);
            $this->_sendRequestToSite(
				[
					'mac' => $mac2,
					'msg' => $ciphertext
				],
				$endpoint
			);
        }
    }

	/**
     * Run the initial Handshake call
     */
    private function _handshake(string $mac, string $msg, string $saltVector)
    {
		$data = [
			'mac' => $mac,
            'msg' => $msg,
            'sv' => $saltVector
		];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->baseUrl.self::REMOTE_END.'handshake');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resp = curl_exec($ch);

        curl_close ($ch);
        
        return json_decode($resp);
	}
	
	/**
	 * Send a request to a Remote site
	 * 
	 * @param array $data - the data to pack into the request body
	 * @param string $endpoint - the Remote endpoint you want to access
	 * 
	 * @return object - the response object
	 */
	private function _sendRequestToSite(array $data, string $endpoint)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->baseUrl.self::REMOTE_END.$endpoint);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resp = curl_exec($ch);
		curl_close ($ch);

		return json_decode($resp);
	}
}
