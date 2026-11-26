<?php
// Runs in the Magento root after the module is installed and compiled. Exercises what a merchant's click does on the
// server: the classes resolve through DI, a GenMagic result file is added to a real product's media gallery without
// touching its main image roles, and GenMagic's public model list is read (keyless). Prints SMOKE OK or exits 1.
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;

require getcwd() . '/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

function check(bool $ok, string $what): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

foreach ([
    \GenMagic\ProductPhotos\Model\PhotoService::class,
    \GenMagic\ProductPhotos\Model\Api\Client::class,
    \GenMagic\ProductPhotos\Model\Config::class,
    \GenMagic\ProductPhotos\Ui\DataProvider\Product\Form\Modifier\ProductPhotos::class,
    \GenMagic\ProductPhotos\Controller\Adminhtml\Photo\Generate::class,
    \GenMagic\ProductPhotos\Controller\Adminhtml\Photo\Add::class,
    \GenMagic\ProductPhotos\Controller\Adminhtml\Photo\Models::class,
] as $class) {
    $om->create($class);
    check(true, "resolves $class");
}

$repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$product = $om->get(\Magento\Catalog\Api\Data\ProductInterfaceFactory::class)->create();
$product->setSku('gm-ci-mug')->setName('GenMagic CI Mug')->setAttributeSetId(4)->setStatus(1)->setVisibility(4)
    ->setTypeId('simple')->setPrice(15)->setWebsiteIds([1]);
$product = $repo->save($product);
$id = (int)$product->getId();
check($id > 0, "product saved ($id)");

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$token = $id . '-0-' . str_repeat('ab', 16) . '.png';
$media = $om->get(\Magento\Framework\Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
$media->writeFile('tmp/genmagic/' . $token, $png);

$service = $om->get(\GenMagic\ProductPhotos\Model\PhotoService::class);
$service->addToGallery($id, $token, false);
$reloaded = $repo->getById($id, false, 0, true);
$entries = $reloaded->getMediaGalleryEntries() ?: [];
check(count($entries) === 1, 'one image in the gallery (' . count($entries) . ')');
check(in_array((string)$reloaded->getImage(), ['', 'no_selection'], true), 'main image role untouched');

try {
    $service->addToGallery($id, $id . '-7-' . str_repeat('cd', 16) . '.png', false);
    check(false, 'a token of another admin is refused');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    check(true, 'a token of another admin is refused');
}

try {
    $models = $service->getModels();
    echo 'LIVE MODELS ' . count($models) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'LIVE MODELS unavailable: ' . $e->getMessage() . PHP_EOL;
}
echo 'SMOKE OK' . PHP_EOL;
