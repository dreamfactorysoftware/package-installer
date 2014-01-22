# DreamFactory Package Installer v1.3.12

DreamFactory Package Installer (DFPI) is a tool for installing applications,
libraries and plug-ins to your [DSP](https://github.com/dreamfactorysoftware/dsp-core).
Packages installed with this tool are safe from system upgrades.

## Packaging and Composer

By default, the DFPI leverages the package manager used by the DSP, [Composer]
(http://getcomposer.org). While this document and the package installer
require a `composer.json` file to install your package, DSP packages are not
required, or limited, to utilize this packaging method. See [Packaging
Without Composer][] for more information on alternative packaging.

### What's Composer?

Composer is a dependency manager tracking local dependencies of your projects and libraries.

See [https://getcomposer.org/](https://getcomposer.org/) for more information and documentation.

### Installation and Usage

No installation is required. To have the DFPI install your package,
you need to set the `composer.json` property [type](http://getcomposer.org/doc/04-schema.md#type) to one of the
following types:

 * `dreamfactory-application`
 * `dreamfactory-library`
 * `dreamfactory-plugin`

#### Applications

Applications are DSP applications that consist of only client-side code. These are installed to your
DSP's `/storage/applications` directory.

#### Plugins/Libraries

Plugins, or libraries are DSP extensions that consist of code or code and UI components. These are installed to your
DSP's `/storage/plugins` directory.

### Specifying Your Package Details

In addition to specifying a package type, the DFPI utilizes the "extra"
section of your project's configuration file (`composer.json`). In this section
you can customize the installation of your DSP package:

    {
        "extra": {
        	"data":
        		"application":	{
					"name":              "Portal Sandbox",
					"api-name":          "pbox",
					"description":       "A sample application that demonstrates the DSP's portal service.",
					"start-url":         "/index.php",
					"url-is-external":   false,
					"import-url":        "https://github.com/dreamfactorysoftware/portal-sandbox/archive/master.zip",
					"active":            true,
					"fullscreen":        false,
					"fullscreen-toggle": true,
					"toggle-location":   "top",
					"config":            "config/app.config.php"
			}.
			"links":             [
				{
					"target": "src/",
					"link":   "pbox"
				}
			]
        }
    }

In the above example we are giving our package a pretty name,
an `api_name` (which defines the route) and a few other details.

## Packaging Without Composer

Doc coming.

## Contributing

All code contributions - including those of people having commit access -
must go through a pull request and approved by a core developer before being
merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

If you would like to help take a look at the [list of issues](http://github.com/dreamfactorysoftware/dsp-core/issues).

## Community

Join our other DSP users on [Google Groups](https://groups.google.com/forum/#!forum/dsp-devs)!

IRC channels are on irc.freenode.org: [#dreamfactory](irc://irc.freenode.org/dreamfactory)
for users and [#dreamfactory-dev](irc://irc.freenode.org/dreamfactory-dev) for
development.

Stack Overflow has a growing collection of
[DSP related questions](http://stackoverflow.com/questions/tagged/dreamfactory-dsp).

## License

Composer is licensed under the Apache 2.0 License - see the LICENSE.txt file
for details
