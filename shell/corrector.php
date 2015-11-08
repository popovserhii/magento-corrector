<?php
/**
 * Script for correction standard magento import file with configurable to magmi format
 *
 * @category Agere
 * @package Agere_Shell
 * @author Popov Sergiy <popov@agere.com.ua>
 * @datetime: 03.11.15 19:15
 */

#ini_set('display_errors', 'on');
#error_reporting(-1);

require_once 'abstract.php';

class Mage_Shell_Corrector extends Mage_Shell_Abstract {

	/** @var  Import/Export log file */
	protected $logFile;

	/** @var \SplFileInfo Standard magento import CSV file */
	protected $importFile;

	protected $delimiter = ';';

	/** @var array Additional attributes */
	protected $addAttributes = array(
		'configurable_attributes', // @todo
		'simples_skus',
		//'configurable_sku',
	);


	public function _construct() {
		$this->logFile = Mage::getBaseDir() . '/var/log/сorrector_data.log';

		return parent::_construct();
	}

	public function run() {
		$fileName = $this->getArg('file');
		if ($fileName) {
			$this->bindSimpleProducts();
		} else {
			echo $this->usageHelp();
		}
	}

	/**
	 * @throws Mage_Core_Exception
	 * @deprecated
	 */
	public function dirtyBind() {
		$importFile = $this->getImportFile();
		$correctedFile = $this->createCorrectedFile();
		$notRelatedFile = $this->createCorrectedFile('_not_related');

		$configurableAttributes = array(
			'Кольца' => 'size_conf,weight_items',
			'Цепочки' => 'chain_lenght,weight_items',
			'Серьги' => 'weight_items',
			'Кулоны' => 'size_conf,weight_items',
			'Колье' => 'chain_lenght,weight_items',
			'Брошки' => 'weight_items',
			'Браслеты' => 'bracelet_lenght,weight_items',
			'Все украшения' => 'size_conf,weight_items',
			'Запонки' => 'weight_items',
			'Ручки' => 'color',
			'Флешки' => 'color',
			'Зажим для галстука' => 'weight_items',
			'Шарм' => 'weight_items',
			'Платки' => 'color,width_scarve,length_scarves,weight_items',
			'Ионизатор' => 'weight_items',
			'Бусы' => 'chain_lenght',
		);

		$importCsv = $this->getCsv($importFile);
		$correctedCsv = $this->getCsv($correctedFile, 'w+');
		$notRelatedCsv = $this->getCsv($notRelatedFile, 'w+');

		$headLine = $importCsv->streamReadCsv($this->delimiter);
		$correctedCsv->streamWriteCsv(array_merge($headLine, $addColumn), $this->delimiter);
		$notRelatedCsv->streamWriteCsv($headLine, $this->delimiter);

		$typeColumn = array_search('type', $headLine);
		$skuColumn = array_search('sku', $headLine);
		$attributeSetColumn = array_search('attribute_set', $headLine);

		$pool = array();
		$notRelated = array();
		while (false !== ($csvLine = $importCsv->streamReadCsv($this->delimiter))) {
			if ('simple' === $csvLine[$typeColumn]) {
				$pool[] = $csvLine;
			} elseif ('configurable' === $csvLine[$typeColumn]) {
				$simpleSkus = array();
				foreach ($pool as $key => $simple) {
					if ($simple[$attributeSetColumn] == $csvLine[$attributeSetColumn]) {
						$simple[] = ''; //configurable_attributes
						$simple[] = ''; // simples_skus
						$simple[] = $csvLine[$skuColumn]; //configurable_sku
						$correctedCsv->streamWriteCsv($simple, $this->delimiter);
						$simpleSkus[] = $simple[$skuColumn];
					} else {
						$notRelated[] = $simple;
					}
				}

				$csvLine[] = $configurableAttributes[$csvLine[$attributeSetColumn]]; //configurable_attributes
				$csvLine[] = implode(',', $simpleSkus); // simples_skus
				$csvLine[] = ''; // configurable_sku
				$correctedCsv->streamWriteCsv($csvLine, $this->delimiter);

				$pool = array();
				unset($simpleSkus);
			} else {
				Mage::throwException(sprintf('Product type %s is not yet supported.', $csvLine[$typeColumn]));
			}
		}

		foreach ($notRelated as $csvLine) {
			$notRelatedCsv->streamWriteCsv($csvLine, $this->delimiter);
		}

		$importCsv->streamClose();
		$correctedCsv->streamClose();
		$notRelatedCsv->streamClose();

		echo 'File successfully corrected!' . "\n";
		echo 'Corrected file save to ' . $correctedFile->getPathname() . "\n";
		echo 'Corrected [not related] file save to ' . $notRelatedFile->getPathname() . "\n";
	}

	public function bindSimpleProducts() {
		$importFile = $this->getImportFile();
		$correctedFile = $this->createCorrectedFile();

		$importCsv = $this->getCsv($importFile);
		$correctedCsv = $this->getCsv($correctedFile, 'w+');

		$headLine = $importCsv->streamReadCsv($this->delimiter);
		$correctedCsv->streamWriteCsv(array_merge($headLine, $this->addAttributes), $this->delimiter);

		$skuColumn = array_search('sku', $headLine);

		while (false !== ($csvLine = $importCsv->streamReadCsv($this->delimiter))) {
			Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
			$sku = $csvLine[$skuColumn];
			/** most efficient execution speed, @see http://magento.stackexchange.com/a/41206 */
			$configurable = Mage::getModel('catalog/product');
			$configurable->load($configurable->getIdBySku($sku));

			$simpleProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $configurable);
			$simpleSkus = array();
			foreach ($simpleProducts as $simpleProduct) {
				$simpleSkus[] = $simpleProduct->getSku();
			}

			$attrs = $configurable->getTypeInstance(true)->getConfigurableAttributesAsArray($configurable);
			$attrsCodes = array();
			foreach ($attrs as $attr) {
				$attrsCodes[] = $attr['attribute_code'];
			}

			$csvLine[] = implode(',', $attrsCodes); //configurable_attributes
			$csvLine[] = implode(',', $simpleSkus); // simples_skus
			$correctedCsv->streamWriteCsv($csvLine, $this->delimiter);
		}

		echo 'Simple products successfully has been bound!' . "\n";
		echo 'Corrected file save to ' . $correctedFile->getPathname() . "\n";

		$importCsv->streamClose();
		$correctedCsv->streamClose();

	}

	protected function getImportFile() {
		if (!$this->importFile && ($filePathname = $this->getArg('file'))) {
			$this->importFile = new \SplFileInfo(Mage::getBaseDir('var') . '/' . $filePathname);
		}


		return $this->importFile;
	}

	protected function createCorrectedFile($suffix = '') {
		$origin = $this->getImportFile();
		$extension = '.' . $origin->getExtension();
		$pathname = $origin->getPath() . '/' . $origin->getBasename($extension) . $suffix . '_corrected' . $extension;
		$file = new \SplFileInfo($pathname);

		return $file;
	}

	/**
	 * Retrieve Usage Help Message
	 */
	public function usageHelp()	{
		return <<<USAGE
Usage:  php -f corrector.php -- [options]

  --file <file.csv>            File must be placed into var/import directory

USAGE;
	}

	protected function getCsv(\SplFileInfo $splFile, $mode = 'r+') {
		$сsv = new Varien_Io_File();
		$сsv->setAllowCreateFolders(true);
		$сsv->open(array('path' => $splFile->getPath()));
		$сsv->streamOpen($splFile->getFilename(), $mode);

		return $сsv;
	}

}

$shell = new Mage_Shell_Corrector();
$shell->run();
