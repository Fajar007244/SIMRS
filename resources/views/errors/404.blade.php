@extends('errors.layout')

@section('title', 'Halaman Tidak Ditemukan')
@section('icon', 'fa-compass')
@section('code', '404')
@section('subtitle', 'Halaman Tidak Ditemukan')
@section('message', $message ?? 'Halaman yang Anda cari tidak ditemukan.')