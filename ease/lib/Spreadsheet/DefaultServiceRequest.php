<?php
/**
 * Copyright 2013 Asim Liaquat
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *	http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Spreadsheet;

/**
 * Service Request. The parent class of all services.
 *
 * @package    Google
 * @subpackage Spreadsheet
 * @version    0.1
 * @author     Asim Liaquat <asimlqt22@gmail.com>
 */
class DefaultServiceRequest implements ServiceRequestInterface
{
	/**
	 * Request object
	 * 
	 * @var \Google\Spreadsheet\Request
	 */
	private $request;

	/**
	 * Initializes the service request object.
	 * 
	 * @param \Google\Spreadsheet\Request $request
	 */
	public function __construct(Request $request) {
		$this->request = $request;
	}

	/**
	 * Get the request object
	 * 
	 * @return \Google\Spreadsheet\Request
	 */
	public function getRequest() {
		return $this->request;
	}

	/**
	 * Executes the api request.
	 * 
	 * @return string the xml response
	 *
	 * @throws \Google\Spreadsheet\Exception If the was a problem with the request.
	 *									   Will throw an exception if the response
	 *									   code is 300 or greater
	 */
	public function execute() {
		$headers = array();
		if(count($this->request->getHeaders()) > 0) {
			foreach($this->request->getHeaders() as $k=>$v) {
				if($k!='Authorization') {
					$headers[] = "$k: $v";
				}
			}
		}
		$headers[] = "Authorization: OAuth " . $this->request->getAccessToken();
		$headers = implode("\r\n", $headers);
		$context['http'] = array(
			'method'=>$this->request->getMethod(),
			'header'=>$headers,
			'timeout'=>5
		);
		if($this->request->getMethod()==='POST' || $this->request->getMethod()==='PUT') {
		   $context['http']['content'] = $this->request->getPost();
		}						
		$context = stream_context_create($context);
		// execute the request applying exponential backoff
		$done = false;
		$try_count = 0;
		while((!$done) && $try_count<=5) {
			if($try_count > 0) {
				sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
			}
			$try_count++;
			try {
		   		$ret = @file_get_contents($this->request->getUrl(), false, $context);
				if(isset($http_response_header) && substr($http_response_header[0], 0, 5)==='HTTP/') {
					$http_response_code_parts = explode(' ', $http_response_header[0], 3);
					$code = intval($http_response_code_parts[1]);
					// an HTTP response code of 408 or higher implies the request failed, but retrying the request might work
					if($code<=407) {
						// the response code wasn't 408 or higher, so we're done.
						$done = true;
					}
				}
			} catch(Exception $e) {
				continue;
			}
		}
		$this->resetRequestParams();
		return $ret;
	}

	/**
	 * Resets the properties of the request object to avoid unexpected behaviour
	 * when making more than one request using the same request object.
	 * 
	 * @return void
	 */
	private function resetRequestParams() {
		$this->request->setMethod(Request::GET);
		$this->request->setPost('');
		$this->request->setFullUrl(null);
		$this->request->setEndpoint('');
		$this->request->setHeaders(array());
	}

}
