@extends('errors.layout')

@section('title', 'Akses Ditolak')
@section('icon', 'fa-lock')
@section('code', '403')
@section('subtitle', 'Akses Ditolak')
@section('message', $message ?? 'Anda tidak memiliki akses ke modul ini.')