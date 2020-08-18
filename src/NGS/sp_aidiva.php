<?php
/**
	@page sp_aidiva


*/
require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("sp_aidiva", "Predict pathogenicity and prioritize variants with AIdiva.");
$parser->addString("vcf", "Path to VCF file.", true);
$parser->addString("outdir", "Path to output directory.", true);
$parser->addString("family", "File showing the family and who is affected.", true, "None");
$parser->addString("ps_name", "HPO terms of the observed phenotypes of the patient.", true, "");
$parser->addString("genome_file", "Reference genome file.", true);
$parser->addString("config", "YAML file with the configuration for AIdiva.", true);
$parser->addInt("threads", "The maximum number of threads used.", true, 1);

extract($parser->parse($argv));

$vcf_name = basename($vcf, ".vcf");
$vcf_indel = $outdir."/".$vcf_name."_indel.vcf";
$vcf_indel_expanded = $outdir."/".$vcf_name."_indel_expanded.vcf";
$vcf_snp = $outdir."/".$vcf_name."_snp.vcf";

$hg19_path = $genome_file;
$feature_list = "SIFT,PolyPhen,REVEL,CADD_PHRED,ABB_SCORE,MAX_AF,segmentDuplication,custom_EIGEN_PHRED,fannsdb_CONDEL,custom_FATHMM_XF,custom_MutationAssessor,phastCons46mammal,phastCons46primate,phastCons46vertebrate,phyloP46mammal,phyloP46primate,phyloP46vertebrate";
$coding_regions = get_path("aidiva")."data/GRCh37_coding_sequences.bed";
$family_file = $family;
$temp_hpo_file_path = "None";
//$hpo_resources = get_path("aidiva")."data/";

//$model_snp = get_path("aidiva")."/data/rf_model_snp_scikit0-19-1.pkl";
//$model_indel = get_path("aidiva")."/data/rf_model_inframe_indel_scikit0-19-1.pkl";

$parser->exec("python3 ".get_path("aidiva")."aidiva/helper_modules/split_vcf_in_indel_and_snp_set.py", "--in_file $vcf --snp_file $vcf_snp --indel_file $vcf_indel", true);
$parser->exec("python3 ".get_path("aidiva")."aidiva/helper_modules/convert_indels_to_snps_and_create_vcf.py", "--in_data $vcf_indel --out_data $vcf_indel_expanded --hg19_path $hg19_path", true);

// annotate VCF
$args = array("-in {$vcf_snp}", "-out ".$outdir."/".$vcf_name."_snp_vep.vcf");
$args[] = "-threads {$threads}";
$parser->execTool("NGS/an_aidiva_vep.php", implode(" ", $args));

$args = array("-in {$vcf_indel_expanded}", "-out ".$outdir."/".$vcf_name."_indel_expanded_vep.vcf");
$args[] = "-threads {$threads}";
$parser->execTool("NGS/an_aidiva_vep.php", implode(" ", $args));

$args = array("-in {$vcf_indel}", "-out ".$outdir."/".$vcf_name."_indel_vep.vcf");
$args[] = "-basic";
$args[] = "-threads {$threads}";
$parser->execTool("NGS/an_aidiva_vep.php", implode(" ", $args));

if ($ps_name != "")
{
	$sample_name = implode('_', explode('_', $ps_name, -1));
	$temp_sample_info = $parser->tempFile(".tsv");
	$parser->exec(get_path("ngs-bits")."/NGSDExportSamples", "-sample {$sample_name} -out {$temp_sample_info} -add_disease_details", true);

	$sample_matrix = Matrix::fromTSV($temp_sample_info);
	$sample_name_index = $sample_matrix->getColumnIndex("name");
	$HPO_column_index = $sample_matrix->getColumnIndex("disease_details_HPO_term_id");

	$hpo_list = array();
	$temp_hpo_file_path = $parser->tempFile(".txt");
	$temp_hpo_file = fopen($temp_hpo_file_path, 'w');

	for ($i = 0; $i < $sample_matrix->rows(); ++$i)
	{
		if ($sample_matrix->get($i, $sample_name_index) == $ps_name)
		{
			$HPO_terms_unprocessed = explode(';', $sample_matrix->get($i, $HPO_column_index));
			for ($j = 0; $j < count($HPO_terms_unprocessed); ++$j)
			{
				$hpo_temp = explode('-', $HPO_terms_unprocessed[$j]);
				fwrite($temp_hpo_file, trim($hpo_temp[0]));
				$hpo_list[] = trim($hpo_temp[0]);
			}
		}
	}

	fclose($temp_hpo_file);
}

// process annotated vcf and perform pathogenicity scoring and prioritization
$args = array();
$args[] = "--snp_vcf ".$outdir."/".$vcf_name."_snp_vep.vcf";
$args[] = "--indel_vcf ".$outdir."/".$vcf_name."_indel_vep.vcf";
$args[] = "--expanded_indel_vcf ".$outdir."/".$vcf_name."_indel_expanded_vep.vcf";
$args[] = "--out_prefix ".$vcf_name."_result";
$args[] = "--workdir $outdir";
if ($temp_hpo_file_path != "")
{
	$args[] = "--hpo_list {$temp_hpo_file_path}";
}
$args[] = "--family_file {$family_file}";
$args[] = "--config {$config}";
$parser->exec("python3 ".get_path("aidiva")."aidiva/run_AIdiva.py", implode(" ", $args), true);

$parser->exec(get_path("ngs-bits")."/VcfSort", "-in ".$outdir."/".$vcf_name."_result.vcf"." -out ".$outdir."/".$vcf_name."_result_sorted.vcf", true);
$parser->exec("bgzip", $outdir."/".$vcf_name."_result_sorted.vcf", true);
$parser->exec("tabix", " -p vcf ".$outdir."/".$vcf_name."_result_sorted.vcf.gz", true);

?>