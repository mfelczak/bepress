# OJS bepress Digital Commons import plugin
Plugin to import bepress Digital Commons journal to OJS 3.1.1 or newer

## Requirements

### Import Files
To get started you will need to request an export archive of your Digital Commons journal article metadata (metadata.xml) and galley files (fulltext.pdf) for all published articles. The article metadata XML will be in a [custom document-export format](sample-metadata.xml).

You will need to review your metadata XML files and ensure that all required data is included. If needed, the metadata.xml files can be edited to manually add any missing, required metadata. The following metadata fields are required:

- title
- author name and email
- publication date
- section/type
- volume/issue

Import files will need to be placed in your OJS server directory, using the following path convention:
- `bepress_xml/journalName/vol#/iss#/#`

where:

- `bepress_xml` is the parent folder for all exported journals,
- `journalName` is a short name of your Digital Commons journal
- `vol#` is the volume number folder that contains all issues for the volume
- `iss#` is the issue number folder that contains all articles for the issue
- `#` is the article # folder that contains the article metadata.xml and fulltext.pdf files

### Plugin installation
This plugin will need to be installed in your **OJS 3.1.1 or newer** installation:
- Ensure that the plugin branch/version matches your version of OJS
- The destination folder for the plugin should be `plugins/importexport/bepress`

For example, to install this plugin via git for OJS 3.1.2:
`cd /path/to/ojs`
`git clone https://github.com/mfelczak/bepress.git plugins/importexport/bepress`
`cd plugins/importexport/bepress`
`git checkout -b stable-3_1_2 origin/stable-3_1_2`
`cd ../../..`
`php lib/pkp/tools/installPluginVersion.php plugins/importexport/bepress/version.xml`

### OJS setup
Your OJS install will also need to include the following:
- a destination journal in OJS for imported content
- an Author user that should be used as the default account for imported articles
- an Editor user that should be assigned to imported articles

## Usage

Login to your server and execute the following in your OJS source directory.

`php tools/importExport.php BepressImportPlugin journal username editorUsername defaultEmail importPath`

where:

- `journal`: the journal into which the articles should be imported (use the OJS journal path)
- `username`: the user to whom imported articles should be assigned; note: this user must have the Author role
- `editorUsername`: the editor to whom imported articles should be assigned; note: this user must have the Journal Editor role and access to the Production stage
- `defaultEmail`: assigned to article metadata when author email not provided in import XML
- `importPath`: full filepath to import bepress files (e.g. /home/user/bepress_xml/journalName)

## Limitations

Due to limitations with exported bepress XML metadata, the plugin imports published articles in sequential order. Imported articles may need to be further sorted in their respective issue’s table of contents in OJS. In addition, while the plugin attempts to preserve issue sections, some section names and assignments may need correction following the import process.

The plugin does not import the following journal content, which can be uploaded following the import:
- Article supplementary files
- Issue cover images

Please note the bepress Digital Commons import plugin is intended for journal content only and does not support the migration of Digital Commons conference or book content.

## Bugs and Enhancements

We welcome bug reports and fixes via github Issues for this project. Feature enhancements may also be contributed via Pull requests for inclusion into the repository.

## CSV to XML Conversion Script

The following CSV to XML conversion script may provide a starting point for converting Bepress metadata to import XML metadata used by this plugin: https://github.com/journalprivacyconfidentiality/jpc-migration
