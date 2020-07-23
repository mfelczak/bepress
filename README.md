# OJS bepress Digital Commons import plugin
Plugin to import bepress Digital Commons journal to OJS 3.1.1 or newer.

This branch is for OJS 3.1.1 compatibility. If you're working with OJS 3.1.2 or OJS 3.2 please use the corresponding branches.

## Requirements

To get started, you will need to export your Digital Commons published article metadata and galley files.

Exported files will need to be placed on your OJS server using the following directory convention:
- `bepress_xml/journalName/vol#/iss#/#`

where:

- `bepress_xml` is the parent folder for all exported journals,
- `journalName` is a short name of your Digital Commons journal
- `vol#` is the volume number folder that contains all issues for the volume
- `iss#` is the issue number folder that contains all articles for the issue
- `#` is the article # folder that contains the article metadata.xml and fulltext.pdf files

You will need an **OJS 3.1.0-1 or newer** installation with the following:
- a destination journal for imported content
- an Author user that should be used as the default account for imported articles
- an Editor user that should be assigned to imported articles

You will need to install the bepress import plugin in your OJS installation to `plugins/importexport/bepress`

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
