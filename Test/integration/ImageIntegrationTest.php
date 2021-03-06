<?php

namespace Tinify\Magento;

use AspectMock;
use Tinify;

class ImageIntegrationTest extends \Tinify\Magento\IntegrationTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->useFilesystemRoot();
        $this->loadArea("adminhtml");

        $logHandler = $this->getObjectManager()->get(
            "Magento\Framework\Logger\Handler\System"
        );
        $this->setProperty($logHandler, "url", $this->getVfs() . "/system.log");

        $this->dbConfig = $this->getObjectManager()->get(
            "Magento\Config\Model\ResourceModel\Config"
        );

        $this->dbConfig->saveConfig(Model\Config::KEY_PATH, "my_api_key", "default", 0);
        $this->dbConfig->saveConfig(Model\Config::TYPES_PATH . "/swatch", 0, "default", 0);

        $config = $this->getObjectManager()->get(
            "Tinify\Magento\Model\Config"
        );
        $this->dir = $config->getMediaDirectory();

        $this->dir->create();

        $this->pngSuboptimal = file_get_contents(__DIR__ . "/../fixtures/example.png");

        $this->dir->create("catalog/product/cache/1/my_image_type");

        $this->dir->writeFile(
            "catalog/product/example.png",
            $this->pngSuboptimal
        );

        /* Magento 2.0.x */
        $this->dir->create(
            "catalog/product/cache/1/my_image_type/beff4985b56e3afdbeabfc89641a4582"
        );

        $this->dir->create(
            "catalog/product/cache/1/swatch_thumb/beff4985b56e3afdbeabfc89641a4582"
        );

        $this->dir->create(
            "catalog/product/cache/1/my_small_image1/beff4985b56e3afdbeabfc89641a4582"
        );

        $this->dir->create(
            "catalog/product/cache/1/my_small_image2/beff4985b56e3afdbeabfc89641a4582"
        );

        /* Magento 2.1.x */
        $this->dir->writeFile(
            "catalog/product/cache/b743fee1b82927b4bcb0975ffb187478/example.png",
            $this->pngSuboptimal
        );

        $this->dir->writeFile(
            "catalog/product/cache/510aa0b6ef97a8d76ecfc57ebaf8e364/example.png",
            $this->pngSuboptimal
        );

        $this->jpgSuboptimal = file_get_contents(__DIR__ . "/../fixtures/example.jpg");

        $this->dir->writeFile(
            "catalog/product/example.jpg",
            $this->jpgSuboptimal
        );

        $this->dir->writeFile(
            "catalog/product/cache/b743fee1b82927b4bcb0975ffb187478/example.jpg",
            $this->jpgSuboptimal
        );

        $this->dir->writeFile(
            "catalog/product/cache/9b43bba90e608d30cac05a77864b5fa3/example.jpg",
            $this->jpgSuboptimal
        );

        $this->dir->writeFile(
            "catalog/product/cache/984a5bf2c84db04bc2e299406efcf53b/example.jpg",
            $this->jpgSuboptimal
        );

        $this->pngOptimal = file_get_contents(__DIR__ . "/../fixtures/example-tiny.png");
        AspectMock\Test::double("Tinify\Source", [
            "fromBuffer" => new Tinify\Result([], $this->pngOptimal)
        ]);

        $this->image = $this->getObjectManager()->create(
            "Magento\Catalog\Model\Product\Image"
        );
    }

    protected function tearDown()
    {
        $this->dir->delete("catalog/product/optimized");
    }

    public function testSaveCreatesOptimizedVersion()
    {
        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $sha = "d519570140157e41611e39513acca2c79ab89b301fcb5e76178db49bc8f26fab";
        $path = "catalog/product/optimized/d/5/{$sha}/example.png";
        $this->assertEquals($this->pngOptimal, $this->dir->readFile($path));
    }

    public function testSaveUpdatesCompressionCount()
    {
        Tinify\Tinify::setCompressionCount(6);

        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $status = $this->getObjectManager()->get(
            "Tinify\Magento\Model\Config\ConnectionStatus"
        );

        $this->assertEquals(6, $status->getCompressionCount());
    }

    public function testSaveDoesNotCreateOptimizedVersionIfDisabled()
    {
        $this->image->setDestinationSubdir("swatch_thumb");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $sha = "d519570140157e41611e39513acca2c79ab89b301fcb5e76178db49bc8f26fab";
        $path = "catalog/product/optimized/d/5/{$sha}/example.png";
        $this->assertFalse($this->dir->isFile($path));
    }

    public function testSaveDoesNotOverwriteOptimizedVersion()
    {
        $sha = "d519570140157e41611e39513acca2c79ab89b301fcb5e76178db49bc8f26fab";
        $path = "catalog/product/optimized/d/5/{$sha}/example.png";
        $this->dir->writeFile($path, "previous binary");

        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $this->assertEquals("previous binary", $this->dir->readFile($path));
    }

    public function testSaveCreatesOptimizedVersionRegardlessOfQuality()
    {
        $image1 = $this->getObjectManager()->create(
            "Magento\Catalog\Model\Product\Image"
        );

        $image1->setDestinationSubdir("my_small_image1");
        $image1->setBaseFile("example.jpg");
        $image1->setWidth(200);
        $image1->setHeight(133);
        $image1->saveFile();

        $image2 = $this->getObjectManager()->create(
            "Magento\Catalog\Model\Product\Image"
        );

        $image2->setDestinationSubdir("my_small_image2");
        $image2->setBaseFile("example.jpg");
        $image2->setWidth(200);
        $image2->setHeight(133);
        $image2->setQuality(31);
        $image2->saveFile();

        $this->assertEquals($image1->getUrl(), $image2->getUrl());
    }

    public function testSaveLogsExceptionOnCompressionError()
    {
        $error = new Tinify\Exception("error");
        AspectMock\Test::double("Tinify\Source", [
            "fromBuffer" => function () use ($error) {
                throw $error;
            }
        ]);

        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $log = file_get_contents($this->getVfs() . "/system.log");
        $this->assertContains(
            "tinify.ERROR: {$error}",
            $log
        );
    }

    public function testGetUrlReturnsOptimizedVersion()
    {
        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        $sha = "d519570140157e41611e39513acca2c79ab89b301fcb5e76178db49bc8f26fab";
        $url = "http://localhost:3000/pub/media/catalog/product/optimized/d/5/{$sha}/example.png";
        $this->assertEquals($url, $this->image->getUrl());
    }

    public function testGetUrlReturnsCachedVersionWhenKeyIsUnset()
    {
        $this->dbConfig->saveConfig(Model\Config::KEY_PATH, "  ", "default", 0);

        $config = $this->getObjectManager()->get(
            "Magento\Framework\App\Config"
        );

        if (method_exists($config, "clean")) {
            /* Clear config cache if possible. */
            $config->clean();
        } else {
            $this->getObjectManager()->get(
                "Magento\Framework\App\Config\ScopePool"
            )->clean();
        }

        $this->image->setDestinationSubdir("my_image_type");
        $this->image->setBaseFile("example.png");
        $this->image->saveFile();

        /* Magento 2.0.x */
        $url1 = "http://localhost:3000/pub/media/catalog/product/cache/" .
            "1/my_image_type/beff4985b56e3afdbeabfc89641a4582/example.png";

        /* Magento 2.1.x */
        $url2 = "http://localhost:3000/pub/media/catalog/product/cache/" .
            "b743fee1b82927b4bcb0975ffb187478/example.png";
        $this->assertContains($this->image->getUrl(), [$url1, $url2]);
    }
}
