#!/usr/local/bin/perl

use warnings;
use strict;
use LWP::Simple;
use Data::Dumper;
use DBI;

my $driver   = "SQLite"; 
my $database = "maps.sq3";
my $dsn = "DBI:SQLite:dbname=$database";
my $dbh = DBI->connect($dsn, "", "", {PrintError => 0, AutoCommit => 0}) or die "Database Connection Error: $DBI::errstr\n";

$dbh->do("DROP TABLE IF EXISTS ASMAPS;") or print $DBI::errstr;
$dbh->do(qq {CREATE TABLE ASMAPS (search VARCHAR(50) PRIMARY KEY NOT NULL, substitute VARCHAR(50) NOT NULL);}) or print $DBI::errstr;

$dbh->do("DROP TABLE IF EXISTS COMMAPS;") or print $DBI::errstr;
$dbh->do(qq {CREATE TABLE COMMAPS (search VARCHAR(50) PRIMARY KEY NOT NULL, substitute VARCHAR(50) NOT NULL);}) or print $DBI::errstr;

$dbh->commit();

my $com_insert = $dbh->prepare(qq{ INSERT INTO COMMAPS (search,substitute) VALUES (?,?); });
my $asnum_insert = $dbh->prepare(qq{ INSERT INTO ASMAPS (search,substitute) VALUES (?,?); });

#
# Community Map.
#
open (COMMSFILE, 'community.txt');
while (<COMMSFILE>) {
	chomp;
	if (not /^#|^\s*$/) {
		my @split = split (/\s+/);
		
		$com_insert->execute($split[0],$split[1]);
		
		if ($com_insert->err) {
			print STDERR "Error [$split[0] $split[1]] : " . $com_insert->errstr . "\n";
		} 
	}
}

$dbh->commit();
close (COMMSFILE); 

#
# AS Number Mappings.
#
my $content = get("http://www.cidr-report.org/as2.0/autnums.html") or die 'Unable to download autnums html page.\n';
open (ASNUMSFILE, '>asnums.txt') or die 'Unable to open asnums.txt file.';

for (split /^/, $content) {
	chomp;
	if(/^\<a .*?\>AS([\d\.]+)\s*\<\/a\>\s*(.*?)\s*$/) {
		my( $a, $b ) = ( $1, $2 );
		
		next if ($b =~ /Private/);
		
		if($a =~ /(\d+)\.(\d+)/) {
			$a = (1 << 16) * $1 + $2;
		}
		
		my @n = split(/\s/, $b);
		if($#n > 0) {
			$b = $n[0];
		}

		$asnum_insert->execute($a,$b);
		
		if ($asnum_insert->err) {
			print STDERR "Error : " . $asnum_insert->errstr . "\n";
		} else { 
			print ASNUMSFILE $a . " ". $b . "\n"; 
		}
    }
}

$dbh->commit();
$dbh->disconnect();
close(ASNUMSFILE);



