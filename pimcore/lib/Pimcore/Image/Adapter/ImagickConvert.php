<?php
namespace Pimcore\Image\Adapter;

use Pimcore\File;
use Pimcore\Image\Adapter;
use Pimcore\Logger;

class ImagickConvert extends Adapter
{
    protected $imagePath = "";

    /**
     * Command used by the CLI script
     *
     * @var string
     */
    protected $command = [];


    protected $outputPath = null;
    /**
     * available options in the convert tool
     *
     * @var null|array
     */
    protected $availableOptions = null;

    /**
     * @var null|\Imagick
     */
    protected $resource = null;

    /**
     * Array with filters used with options
     *
     * @var array
     */
    protected $filters = [];

    /**
     * @param $imagePath
     * @param array $options
     * @return ImagickConvert
     */
    public function load($imagePath, $options = [])
    {
        // support image URLs
        if (preg_match("@^https?://@", $imagePath)) {
            $tmpFilename = "imagick_auto_download_" . md5($imagePath) . "." . File::getFileExtension($imagePath);
            $tmpFilePath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;

            $this->tmpFiles[] = $tmpFilePath;

            File::put($tmpFilePath, \Pimcore\Tool::getHttpData($imagePath));
            $imagePath = $tmpFilePath;
        }

        if (!stream_is_local($imagePath)) {
            // imagick is only able to deal with local files
            // if your're using custom stream wrappers this wouldn't work, so we create a temp. local copy
            $tmpFilePath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/imagick-tmp-" . uniqid() . "." . File::getFileExtension($imagePath);
            copy($imagePath, $tmpFilePath);
            $imagePath = $tmpFilePath;
            $this->tmpFiles[] = $imagePath;
        }

        $this->imagePath = $imagePath;

        $this->initResource();

        $this->setModified(false);

        return $this;
    }

    /**
     * @return ImagickConvert
     */
    protected function initResource()
    {
        if (null === $this->resource) {
            $this->resource = new \Imagick();
        }
        $this->resource->readImage($this->imagePath);
        $this->setWidth($this->resource->getImageWidth())
            ->setHeight($this->resource->getImageHeight());

        return $this;
    }

    /**
     * Save the modified image output in the specified path as the first argument.
     *
     * @param $path
     * @param null $format
     * @param null $quality
     * @return $this
     */
    public function save($path, $format = null, $quality = null)
    {
        $command = ((string) $this) . $path;
        recursiveCopy($this->imagePath, $path);
        exec($command);

        return $this;
    }

    /**
     * @return ImagickConvert
     */
    protected function destroy()
    {
        foreach($this->tmpFiles as $tmpFile) {
            unlink($tmpFile);
        }

        return $this;
    }

    /**
     * Resize the image
     *
     * @param $width
     * @param $height
     * @return $this
     */
    public function resize($width, $height)
    {
        $this->addOption('resize', "{$width}x{$height}");
        $this->setWidth($width);
        $this->setHeight($height);
        return $this;
    }

    /**
     * Adds frame which cause that the image gets exactly the entered dimensions by adding borders.
     *
     * @param $width
     * @param $height
     * @return ImagickConvert
     */
    public function frame($width, $height)
    {
        $this->contain($width, $height);
        $frameWidth = $width - $this->getWidth() == 0 ? 0 : ($width - $this->getWidth()) / 2;
        $frameHeight = $height - $this->getHeight() == 0 ? 0 : ($height - $this->getHeight()) / 2;
        $this->addOption('frame', "{$frameWidth}x{$frameHeight}")
            ->addOption('alpha', 'Set');

        return $this;
    }

    /**
     * @param int $tolerance
     * @return ImagickConvert
     */
    public function trim($tolerance)
    {
        $this->addOption('trim', $tolerance);

        return $this;
    }

    /**
     * Rotates the image with the given angle.
     * @param $angle
     * @return ImagickConvert
     */
    public function rotate($angle)
    {
        $this->addOption('rotate', $angle)->addOption('alpha', 'Set');
        return $this;
    }

    /**
     * Cuts out a box of the image starting at the given X,Y coordinates and using the width and height.
     *
     * @param $x
     * @param $y
     * @param $width
     * @param $height
     * @return ImagickConvert
     */
    public function crop($x, $y, $width, $height)
    {
        $this->addOption('crop', "{$width}x{$height}+{$x}+{$y}");

        return $this;
    }

    /**
     * Set the background color of the image.
     *
     * @param $color
     * @return ImagickConvert
     */
    public function setBackgroundColor($color)
    {

        $this->addOption('background', "\"{$color}\"");

        return $this;
    }

    /**
     * Rounds the corners to the given width/height.
     *
     * @TODO hasn't done yet. At the moment it's using width and height as a rounded values.
     *
     * @param $width
     * @param $height
     * @return ImagickConvert
     */
    public function roundCorners($width, $height)
    {
        //creates the mask for rounded corners
        $mask = new ImagickConvert();
        $mask->addOption('size', "{$this->getWidth()}x{$this->getHeight()}")
            ->addOption('draw', "'roundRectangle 0,0 {$this->getWidth()},{$this->getHeight()} {$width},{$height}'");
        $mask->addFilter('draw', 'xc:none');
        $tmpFilename = "imagick_mask_" . md5($this->imagePath) . '.png';
        $maskTargetPath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;
        exec((string) $mask . ' ' . $maskTargetPath);
        $this->tmpFiles[] = $maskTargetPath;

        $this
            ->addOption('matte', $maskTargetPath)
            ->addOption('compose', 'DstIn')
            ->addOption('composite', '')
        ;

        return $this;
    }

    /**
     * @TODO find simpler way
     *
     * @param $image
     * @param null $mode
     */
    public function setBackgroundImage($image, $mode = null)
    {

        /*$image = ltrim($image, "/");
        $imagePath = PIMCORE_DOCUMENT_ROOT . "/" . $image;

        if (is_file($imagePath)) {
            $newImage = new ImagickConvert();
            $newImage->load($imagePath);

            if ($mode == "cropTopLeft") {
                $newImage->crop($this->getWidth(), $this->getHeight(), 0, 0);
            } else {
                // default behavior (fit)
                $newImage->resize($this->getWidth(), $this->getHeight());
            }

            $tmpFilename = "imagick_mask_" . md5($this->imagePath) . '.png';
            $tmpFilepath = PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $tmpFilename;
            $this->tmpFiles[] = $tmpFilepath;

            $newImage->save($tmpFilepath;)
        }


        return $this;*/
    }

    public function addOverlay($image, $x = 0, $y = 0, $alpha = 100, $composite = "COMPOSITE_DEFAULT", $origin = 'top-left')
    {
    }

    public function addOverlayFit($image, $composite = "COMPOSITE_DEFAULT")
    {
    }

    /**
     * @param $image
     * @return ImagickConvert
     */
    public function applyMask($image)
    {
        $this->addOption('write-mask', $image);

        return $this;
    }

    /**
     * Cuts out a box of the image starting at the given X,Y coordinates and using the percentage values of width and height.
     *
     * @param $x
     * @param $y
     * @param $width
     * @param $height
     * @return $this
     */
    public function cropPercent($x, $y, $width, $height)
    {
        $this->addOption('crop-percent', "{$width}%x{$height}%+{$x}+{$y}");

        return $this;
    }

    /**
     * Converts the image into a linear-grayscale image.
     *
     * @return ImagickConvert
     */
    public function grayscale($method = "Rec709Luminance")
    {
        $this->addOption('grayscale', $method);

        return $this;
    }

    /**
     * Applies the sepia effect into the image.
     *
     * @return ImagickConvert
     */
    public function sepia()
    {
        $this->addOption('sepia-tone', "85%");
        return $this;
    }

    /**
     * Sharpen the image.
     *
     * @param int $radius
     * @param float $sigma
     * @param float $amount
     * @param float $threshold
     * @return ImagickConvert
     */
    public function sharpen($radius = 0, $sigma = 1.0, $amount = 1.0, $threshold = 0.05)
    {
        $this->addOption('sharpen', "'{$radius}x{$sigma}+$amount+$threshold'");
        return $this;
    }

    /**
     * Blur the image.
     *
     * @param int $radius
     * @param float $sigma
     * @return $this
     */
    public function gaussianBlur($radius = 0, $sigma = 1.0)
    {
        $this->addOption('gaussian-blur', "{$radius}x{$sigma}");
        return $this;
    }

    /**
     * Brightness, saturation and hue setting of the image.
     *
     * @param int $brightness
     * @param int $saturation
     * @param int $hue
     * @return ImagickConvert
     */
    public function brightnessSaturation($brightness = 100, $saturation = 100, $hue = 100)
    {
        $this->addOption('modulate', "{$brightness},{$saturation},{$hue}");
        return $this;
    }

    /**
     * Creates vertical or horizontal mirror of the image.
     *
     * @param $mode
     * @return ImagickConvert
     */
    public function mirror($mode)
    {
        if ($mode == "vertical") {
            $this->addOption('flip');
        } elseif ($mode == "horizontal") {
            $this->addOption('flop');
        }

        return $this;
    }

    /**
     * Add option to the command
     *
     * @param $name
     * @param null $value
     * @return $this
     */
    public function addOption($name, $value = null)
    {
        $this->command[$name] = $value;

        return $this;
    }

    /**
     * @param $optionName
     * @param $filterValue
     * @return $this
     */
    public function addFilter($optionName, $filterValue)
    {
        if(! isset($this->filters[$optionName])) {
            $this->filters[$optionName] = [];
        }

        $this->filters[$optionName][] = $filterValue;

        return $this;
    }

    /**
     * @param $optionName
     * @return array
     */
    public function getFilters($optionName)
    {
        return isset($this->filters[$optionName]) ? $this->filters[$optionName] : [];
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return "convert {$this->getOptionsAsString()}";
    }

    /**
     * Returns options parameter for the convert command
     *
     * @return string
     */
    public function getOptionsAsString()
    {
        $options = $this->imagePath . ' ';
        foreach($this->command as $commandKey => $commandValue) {
            $options .= implode(' ', $this->getFilters($commandKey)) . ' ';
            $options .= "-{$commandKey} {$commandValue} ";
        }

        return $options;
    }

}