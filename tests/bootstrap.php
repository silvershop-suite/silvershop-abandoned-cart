<?php

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;

// silvershop/core expects a global Page (Product extends Page). When this module runs its own tests it is
// the root package with no project app/, so stub it. Guarded so it never clashes with the app/code/Page.php
// that silverstripe/recipe-testing scaffolds into a test webroot.
if (!class_exists('Page')) {
    class Page extends SiteTree
    {
    }
}

if (!class_exists('PageController')) {
    /**
     * @extends ContentController<Page>
     */
    class PageController extends ContentController
    {
    }
}

require_once dirname(__DIR__) . '/vendor/silverstripe/framework/tests/bootstrap.php';
