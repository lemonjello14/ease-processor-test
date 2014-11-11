<?php
/**
* Copyright 2013 Asim Liaquat
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*    http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

namespace Google\Spreadsheet;

/**
* Autoloads classes in this package.
*
* @package    Google
* @subpackage Spreadsheet
* @version    0.1
* @author     Asim Liaquat <asimlqt22@gmail.com>
*/
class Autoloader
{
	private $src;

	public function __construct() {
		$this->src = realpath(dirname(__FILE__) . '/../..') . '/';
	}

	private $classmap = array(
		'Google\\Spreadsheet\\Request'=>'lib/Spreadsheet/Request',
		'Google\\Spreadsheet\\ServiceRequestInterface'=>'lib/Spreadsheet/ServiceRequestInterface',
		'Google\\Spreadsheet\\DefaultServiceRequest'=>'lib/Spreadsheet/DefaultServiceRequest',
		'Google\\Spreadsheet\\Exception'=>'lib/Spreadsheet/Exception',
		'Google\\Spreadsheet\\ServiceRequestFactory'=>'lib/Spreadsheet/ServiceRequestFactory',
		'Google\\Spreadsheet\\SpreadsheetService'=>'lib/Spreadsheet/SpreadsheetService',
		'Google\\Spreadsheet\\SpreadsheetFeed'=>'lib/Spreadsheet/SpreadsheetFeed',
		'Google\\Spreadsheet\\Spreadsheet'=>'lib/Spreadsheet/Spreadsheet',
		'Google\\Spreadsheet\\WorksheetFeed'=>'lib/Spreadsheet/WorksheetFeed',
		'Google\\Spreadsheet\\Worksheet'=>'lib/Spreadsheet/Worksheet',
		'Google\\Spreadsheet\\ListFeed'=>'lib/Spreadsheet/ListFeed',
		'Google\\Spreadsheet\\ListEntry'=>'lib/Spreadsheet/ListEntry',
		'Google\\Spreadsheet\\CellFeed'=>'lib/Spreadsheet/CellFeed',
		'Google\\Spreadsheet\\CellEntry'=>'lib/Spreadsheet/CellEntry',
		'Google\\Spreadsheet\\Util'=>'lib/Spreadsheet/Util',
	);

	public function autoload($cls) {
		if(array_key_exists($cls, $this->classmap)) {
			require_once $this->src . $this->classmap[$cls] . '.php';
		}
	}

}

spl_autoload_register(array(new Autoloader(), 'autoload'));
