<?php
// hotcrpdocument.inc -- document helper class for HotCRP papers
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $ConfFilestore;
$ConfFilestore = null;

class HotCRPDocument {

    var $type;
    var $option;

    function __construct($document_type, $option = null) {
        $this->type = $document_type;
        if ($option)
            $this->option = $option;
        else if ($this->type > 0)
            $this->option = paperOptions($document_type);
        else
            $this->option = null;
    }

    function mimetypes($doc = null, $docinfo = null) {
        global $Opt;
        require_once("paperoption.inc");
        if ($this->type > 0 && !$this->option)
            return null;
        $optionType = ($this->option ? $this->option->type : null);
        $mimetypes = array();
        if (PaperOption::type_takes_pdf($optionType))
            $mimetypes[] = Mimetype::lookup("pdf");
        if ($optionType === null && !defval($Opt, "disablePS"))
            $mimetypes[] = Mimetype::lookup("ps");
        if ($optionType == PaperOption::T_SLIDES
            || $optionType == PaperOption::T_FINALSLIDES) {
            $mimetypes[] = Mimetype::lookup("ppt");
            $mimetypes[] = Mimetype::lookup("pptx");
        }
        if ($optionType == PaperOption::T_VIDEO
            || $optionType == PaperOption::T_FINALVIDEO) {
            $mimetypes[] = Mimetype::lookup("mp4");
            $mimetypes[] = Mimetype::lookup("avi");
        }
        return $mimetypes;
    }

    function database_storage($doc, $docinfo) {
        global $Conf;
        $columns = array("paperId" => $docinfo->paperId,
                         "timestamp" => $doc->timestamp,
                         "mimetype" => $doc->mimetype,
                         "paper" => $doc->content);
        if ($Conf->sversion >= 28) {
            $columns["sha1"] = $doc->sha1;
            $columns["documentType"] = $this->type;
        }
        if ($Conf->sversion >= 45 && $doc->filename)
            $columns["filename"] = $doc->filename;
        return array("PaperStorage", "paperStorageId", $columns, "paper");
    }

    function filestore_pattern($doc, $docinfo) {
        global $Opt, $ConfSitePATH, $ConfMulticonf, $ConfFilestore;
        if ($ConfFilestore === null) {
            $fdir = defval($Opt, "filestore");
            if (!$fdir)
                return ($ConfFilestore = false);
            if ($fdir === true)
                $fdir = "$ConfSitePATH/filestore";
            if (isset($Opt["multiconference"]) && $Opt["multiconference"])
                $fdir = str_replace("*", $ConfMulticonf, $fdir);

            $fpath = $fdir;
            $use_subdir = defval($Opt, "filestoreSubdir", false);
            if ($use_subdir && ($use_subdir === true || $use_subdir > 0))
                $fpath .= "/%" . ($use_subdir === true ? 2 : $use_subdir) . "h";
            $fpath .= "/%h%x";

            $ConfFilestore = array($fdir, $fpath);
        }
        return $ConfFilestore;
    }

    function load_database_content($doc) {
        global $Conf;
        if (!$doc->paperStorageId) {
            if ($this->type == DTYPE_SUBMISSION)
                $doc->error_text = "Paper #" . $doc->paperId . " has not been uploaded.";
            else if ($this->type == DTYPE_FINAL)
                $doc->error_text = "Paper #" . $doc->paperId . "’s final copy has not been uploaded.";
            else
                $doc->error_text = "";
            return false;
        }
        assert(isset($doc->paperStorageId));
        $result = $Conf->q("select paper, compression from PaperStorage where paperStorageId=" . $doc->paperStorageId);
        $ok = true;
        if (!$result || !($row = edb_row($result))) {
            $doc->content = "";
            $ok = false;
        } else if ($row[1] == 1)
            $doc->content = gzinflate($row[0]);
        else
            $doc->content = $row[0];
        $doc->size = strlen($doc->content);
        return $ok;
    }

}