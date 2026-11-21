#!/usr/bin/env python3
"""Finto endpoint SPARQL per le prove (richiede: pip install rdflib).

Simula una piccola parte dei dati di dati.cultura.gov.it: luoghi della cultura (Cultural-ON) con sede,
indirizzo e coordinate, un grafo di indirizzi con numeri civici, beni culturali con geometria WKT e
due dataset DCAT. Uso:  python tests/sparql-mock.py 8098   ->  http://127.0.0.1:8098/sparql
"""
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

from rdflib import Dataset, Literal, Namespace, URIRef
from rdflib.namespace import DCAT, DCTERMS, RDF, RDFS, XSD

CIS = Namespace('http://dati.beniculturali.it/cis/')
CLV = Namespace('https://w3id.org/italia/onto/CLV/')
GEO = Namespace('http://www.w3.org/2003/01/geo/wgs84_pos#')
GSP = Namespace('http://www.opengis.net/ont/geosparql#')
ARCO = Namespace('https://w3id.org/arco/ontology/arco/')
R = 'http://dati.beniculturali.it/resource/'

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8098
ds = Dataset(default_union=True)

g = ds.graph(URIRef('http://dati.beniculturali.it/luoghi'))
g.add((CIS.CulturalInstituteOrSite, RDFS.label, Literal('Istituto o luogo della cultura', lang='it')))
citta = ['Roma', 'Milano', 'Napoli', 'Torino', 'Firenze']
for i in range(1, 31):
    s = URIRef(f'{R}luogo/{i}')
    site = URIRef(f'{R}sede/{i}')
    addr = URIRef(f'{R}indirizzo-luogo/{i}')
    num = URIRef(f'{R}civico-luogo/{i}')
    c = citta[i % 5]
    g.add((s, RDF.type, CIS.CulturalInstituteOrSite))
    g.add((s, RDFS.label, Literal(f'{"Museo" if i % 2 else "Biblioteca"} civico {i} di {c}', lang='it')))
    g.add((s, CIS.hasSite, site))
    g.add((site, RDF.type, CIS.Site))
    g.add((site, CIS.siteAddress, addr))
    g.add((site, GEO.lat, Literal(f'{41 + i / 100:.5f}', datatype=XSD.decimal)))
    g.add((site, GEO.long, Literal(f'{12 + i / 100:.5f}', datatype=XSD.decimal)))
    g.add((addr, RDF.type, CLV.Address))
    g.add((addr, CLV.fullAddress, Literal(f'Via Garibaldi {i}, {c}')))
    g.add((addr, CLV.hasNumber, num))
    g.add((num, CLV.streetNumber, Literal(str(i))))
    # due nomi per alcuni luoghi: devono finire nella stessa cella
    if i % 7 == 0:
        g.add((s, RDFS.label, Literal(f'Polo culturale {i}', lang='it')))

g2 = ds.graph(URIRef('http://dati.beniculturali.it/indirizzi'))
for i in range(1, 2501):
    a = URIRef(f'{R}indirizzo/{i:05d}')
    g2.add((a, RDF.type, CLV.Address))
    g2.add((a, CLV.fullAddress, Literal(f'Via Roma {i}, Comune {i % 50}')))
    g2.add((a, CLV.hasStreetToponym, Literal('Via Roma')))
    g2.add((a, CLV.streetNumber, Literal(str(i))))
    g2.add((a, GEO.lat, Literal(f'{40 + (i % 300) / 100:.4f}', datatype=XSD.decimal)))
    g2.add((a, GEO.long, Literal(f'{9 + (i % 400) / 100:.4f}', datatype=XSD.decimal)))

g3 = ds.graph(URIRef('http://dati.beniculturali.it/arco'))
for i in range(1, 21):
    b = URIRef(f'{R}bene/{i}')
    geom = URIRef(f'{R}geometria/{i}')
    g3.add((b, RDF.type, ARCO.ArchaeologicalProperty))
    g3.add((b, RDFS.label, Literal(f'Villa romana {i}', lang='it')))
    g3.add((b, GSP.hasGeometry, geom))
    g3.add((geom, GSP.asWKT, Literal(f'<http://www.opengis.net/def/crs/OGC/1.3/CRS84> POINT({13 + i / 50} {42 + i / 50})', datatype=GSP.wktLiteral)))

g4 = ds.graph(URIRef('http://dati.beniculturali.it/catalogo'))
for i, (t, url) in enumerate([('Elenco dei luoghi della cultura con indirizzi', f'http://127.0.0.1:{PORT}/files/luoghi.csv'),
                              ('Numeri civici dei musei statali', f'http://127.0.0.1:{PORT}/files/civici.csv')], 1):
    d = URIRef(f'{R}dataset/{i}')
    dist = URIRef(f'{R}distribuzione/{i}')
    g4.add((d, RDF.type, DCAT.Dataset))
    g4.add((d, DCTERMS.title, Literal(t, lang='it')))
    g4.add((d, DCTERMS.description, Literal('Dataset di prova con indirizzi e coordinate')))
    g4.add((d, DCAT.distribution, dist))
    g4.add((dist, DCAT.downloadURL, URIRef(url)))


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def answer(self, params):
        q = (params.get('query') or [''])[0]
        if not q:
            self.send_response(400)
            self.end_headers()
            self.wfile.write(b'query mancante')
            return
        try:
            res = ds.query(q)
        except Exception as e:  # come Virtuoso: errore in testo semplice
            self.send_response(400)
            self.send_header('Content-Type', 'text/plain')
            self.end_headers()
            self.wfile.write(f'SPARQL error: {e}'.encode())
            return
        if res.type in ('CONSTRUCT', 'DESCRIBE'):
            body, ctype = res.serialize(format='nt'), 'application/n-triples'
        else:
            body, ctype = res.serialize(format='json'), 'application/sparql-results+json'
        if isinstance(body, str):
            body = body.encode()
        self.send_response(200)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        u = urlparse(self.path)
        if u.path.startswith('/files/'):
            body = 'nome,indirizzo,lat,long\nMuseo 1,"Via Roma 1, Roma",41.9,12.5\n'.encode()
            self.send_response(200)
            self.send_header('Content-Type', 'text/csv')
            self.end_headers()
            self.wfile.write(body)
            return
        self.answer(parse_qs(u.query))

    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0))
        self.answer(parse_qs(self.rfile.read(n).decode()))


print(f'Endpoint SPARQL di prova: http://127.0.0.1:{PORT}/sparql', flush=True)
ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
