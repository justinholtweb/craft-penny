<?php

namespace justinholtweb\penny\web\assets\hosted;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The hosted editing page.
 *
 * Depends on Craft's own control panel bundle, which is the point: the fields on this page are
 * real control panel field inputs, and they need their scripts, their styles and their `Craft.*`
 * globals to behave the way the recipient expects them to.
 */
class HostedAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [CraftCpAsset::class];
    public $css = ['penny-hosted.css'];
    public $js = ['penny-hosted.js'];
}
