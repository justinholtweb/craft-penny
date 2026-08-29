<?php

namespace justinholtweb\penny\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * Control panel dressing: the invite editor's own behaviour, and the stripped-down chrome an
 * invited recipient sees on the Pro control panel surface.
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CraftCpAsset::class];
    public $css = ['penny-cp.css'];
    public $js = ['penny-cp.js'];
}
