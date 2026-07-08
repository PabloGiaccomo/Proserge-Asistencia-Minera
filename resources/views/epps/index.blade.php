@extends('layouts.app')

@section('title', 'EPP - Proserge')

@section('content')
@include('epps.partials.workspace', ['embedded' => false])
@endsection
